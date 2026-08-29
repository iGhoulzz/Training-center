<?php

declare(strict_types=1);

use App\Domain\Enrollment\Filament\Resources\StudentResource\Pages\ListStudents;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Staff\Actions\ResetPortalCredentialAction;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Exceptions\ProtectedAccountException;
use App\Domain\Staff\Exceptions\StudentHasPortalAccountException;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Livewire\Notifications;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('resets the linked portal account through a student record', function (): void {
    $actor = User::factory()->create();
    $actor->givePermissionTo('reset_portal_credential');
    $student = Student::factory()->withAccount()->create();
    $account = $student->user()->sole();
    $originalPassword = $account->password;

    $plain = app(ResetPortalCredentialAction::class)->execute($actor->fresh(), $student);

    expect($plain)->toHaveLength(16)
        ->and($account->fresh()->password)->not->toBe($originalPassword)
        ->and($account->fresh()->must_change_password)->toBeTrue()
        ->and(Hash::check($plain, $account->fresh()->password))->toBeTrue();
});

it('authorizes portal credential resets inside the action', function (): void {
    $student = Student::factory()->withAccount()->create();

    expect(fn (): string => app(ResetPortalCredentialAction::class)->execute(User::factory()->create(), $student))
        ->toThrow(AuthorizationException::class);
});

it('refuses a reset for a student without a linked account', function (): void {
    $actor = User::factory()->create();
    $actor->givePermissionTo('reset_portal_credential');
    $student = Student::factory()->create(['user_id' => null]);

    expect(fn (): string => app(ResetPortalCredentialAction::class)->execute($actor->fresh(), $student))
        ->toThrow(StudentHasPortalAccountException::class);
});

it('refuses a reset for a student whose linked account is trashed', function (): void {
    $actor = User::factory()->create();
    $actor->givePermissionTo('reset_portal_credential');
    $student = Student::factory()->withAccount()->create();
    $student->user()->sole()->delete();

    expect(fn (): string => app(ResetPortalCredentialAction::class)->execute($actor->fresh(), $student))
        ->toThrow(StudentHasPortalAccountException::class);
});

it('refuses a deactivated linked account with administrative access', function (): void {
    // canAccessPanel() would return false for this account because it is
    // inactive. The Action must instead test the durable permission directly.
    $actor = User::factory()->create();
    $actor->givePermissionTo('reset_portal_credential');
    $account = User::factory()->create(['is_active' => false]);
    app(SystemRoleWriter::class)->assignRoles($account, 'staff');
    $student = Student::factory()->create(['user_id' => $account->getKey()]);

    expect($account->fresh()->can('access_admin_panel'))->toBeTrue();

    expect(fn (): string => app(ResetPortalCredentialAction::class)->execute($actor->fresh(), $student))
        ->toThrow(ProtectedAccountException::class);
});

it('resets a portal credential through the student register action', function (): void {
    $actor = User::factory()->create();
    $actor->givePermissionTo(
        'access_admin_panel',
        'view_any_student',
        'view_student',
        'reset_portal_credential',
    );
    $student = Student::factory()->withAccount()->create();

    Livewire::actingAs($actor->fresh())
        ->test(ListStudents::class)
        ->callTableAction('resetPortalCredential', $student)
        ->assertNotified(__('credentials.reset_complete'));

    expect($student->fresh()->user->must_change_password)->toBeTrue();
});

it('rejects a signed-in student on their next livewire request after a reset', function (): void {
    $actor = User::factory()->create();
    $actor->givePermissionTo('reset_portal_credential');
    $account = User::factory()->create([
        'must_change_password' => false,
        'password' => Hash::make('existing-password-1'),
    ]);
    app(SystemRoleWriter::class)->assignRoles($account, 'student');
    $student = Student::factory()->create(['user_id' => $account->getKey()]);

    $this->actingAs($account->fresh(), 'student');

    $html = (string) $this->get('/portal/password-change')->assertSuccessful()->getContent();
    preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

    $snapshot = collect($matches[1])
        ->map(fn (string $raw): string => html_entity_decode($raw, ENT_QUOTES))
        ->first(function (string $candidate): bool {
            $decoded = json_decode($candidate, true);

            return ($decoded['memo']['name'] ?? null) === Notifications::class;
        });

    expect($snapshot)->not->toBeNull('The portal page did not render its notification component.');

    expect(session()->has('password_hash_student'))->toBeTrue(
        'The portal page did not establish the student guard session hash.'
    );

    $plain = app(ResetPortalCredentialAction::class)->execute($actor->fresh(), $student);

    expect(Hash::check($plain, $account->fresh()->password))->toBeTrue();
    expect(app('auth')->getDefaultDriver())->toBe('student');

    /*
     * The reset deliberately raises the forced-change flag too. Clear only that
     * second guard here so this assertion identifies AuthenticateSession's
     * password-hash check rather than a redirect issued by ForcePasswordChange.
     */
    $account->fresh()->forceFill(['must_change_password' => false])->save();

    // The test harness retains the user object passed to actingAs(), unlike the
    // next browser request, which reloads it from the session provider.
    auth('student')->setUser($account->fresh());

    $this->withHeaders(['X-Livewire' => 'true'])
        ->postJson(app(HandleRequests::class)->getUpdateUri(), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => [],
                'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
            ]],
        ])
        ->assertRedirect('/portal/login');
});
