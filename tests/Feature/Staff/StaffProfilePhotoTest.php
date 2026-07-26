<?php

declare(strict_types=1);

use App\Domain\Staff\Models\StaffProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Private staff photos are rendered inline, but never made public.
 *
 * Every request re-runs StaffProfilePolicy::view() and the controller owns both
 * disk and directory identity. A database row cannot turn the avatar endpoint
 * into a certificate download or arbitrary private-disk reader.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    Storage::fake('private');

    $this->bytes = makePngBytes();
    $this->path = 'staff-photos/'.Str::ulid()->toString().'.png';
    Storage::disk('private')->put($this->path, $this->bytes);

    $this->profile = StaffProfile::factory()->create([
        'profile_photo_path' => $this->path,
    ]);

    $this->url = route('staff.profiles.photo', $this->profile);

    $this->userWith = function (string ...$permissions): User {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(...$permissions);

        return $user->fresh();
    };
});

it('streams exact photo bytes inline to an actor who may view the profile', function () {
    $response = $this->actingAs(($this->userWith)('view_staff_profile'))
        ->get($this->url);

    $response->assertOk();

    expect($response->streamedContent())->toBe($this->bytes)
        ->and($response->headers->get('content-disposition'))->toContain('inline')
        ->and($response->headers->get('cache-control'))->toContain('private')
        ->and($response->headers->get('cache-control'))->toContain('no-store')
        ->and($response->headers->get('x-content-type-options'))->toBe('nosniff');
});

it('refuses an authenticated actor who may not view the profile', function () {
    $this->actingAs(($this->userWith)('view_staff_certificate'))
        ->get($this->url)
        ->assertForbidden();
});

it('refuses an inactive actor even when their retained permission allows viewing', function () {
    $inactive = ($this->userWith)('view_staff_profile');
    $inactive->update(['is_active' => false]);

    $this->actingAs($inactive->fresh())
        ->get($this->url)
        ->assertForbidden();
});

it('refuses an unauthenticated photo request', function () {
    $this->get($this->url)->assertForbidden();
});

it('404s for a missing photo file', function () {
    Storage::disk('private')->delete($this->path);

    $this->actingAs(($this->userWith)('view_staff_profile'))
        ->get($this->url)
        ->assertNotFound();
});

it('404s for an empty, traversing, or wrong-directory stored path', function (?string $path) {
    $this->profile->update(['profile_photo_path' => $path]);

    $this->actingAs(($this->userWith)('view_staff_profile'))
        ->get($this->url)
        ->assertNotFound();
})->with([
    'empty path' => null,
    'parent traversal' => '../../../../etc/passwd',
    'nested traversal' => 'staff-photos/../staff-certificates/secret.pdf',
    'certificate directory' => 'staff-certificates/secret.pdf',
    'nested photo directory' => 'staff-photos/nested/avatar.png',
    'inline html' => 'staff-photos/'.Str::ulid()->toString().'.html',
    'non-generated filename' => 'staff-photos/avatar.png',
]);
