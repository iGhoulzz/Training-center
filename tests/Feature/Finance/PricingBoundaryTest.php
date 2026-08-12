<?php

declare(strict_types=1);

use App\Domain\Enrollment\Filament\Resources\BatchResource\Pages\CreateBatch;
use App\Domain\Enrollment\Filament\Resources\BatchResource\Pages\EditBatch;
use App\Domain\Enrollment\Filament\Resources\CourseResource\Pages\CreateCourse;
use App\Domain\Enrollment\Filament\Resources\CourseResource\Pages\EditCourse;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Finance\Actions\UpdateBatchPriceAction;
use App\Domain\Finance\Actions\UpdateCoursePriceAction;
use App\Domain\Finance\Services\PricingService;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->makePricingActor = function (string $role): User {
        $actor = User::factory()->create(['is_active' => true]);
        app(SystemRoleWriter::class)->assignRoles($actor, $role);

        return $actor->refresh();
    };
});

it('resolves an inheriting batch price live while an override stays frozen', function () {
    $course = Course::factory()->create(['default_price' => '100.000']);
    $inheriting = Batch::factory()->for($course)->create(['price' => null]);
    $overriding = Batch::factory()->for($course)->create(['price' => '80.000']);
    $pricing = app(PricingService::class);

    expect($pricing->priceForBatch($inheriting)->toDecimal())->toBe('100.000')
        ->and($pricing->priceForBatch($overriding)->toDecimal())->toBe('80.000');

    $course->update(['default_price' => '125.500']);

    expect($pricing->priceForBatch($inheriting->refresh())->toDecimal())->toBe('125.500')
        ->and($pricing->priceForBatch($overriding->refresh())->toDecimal())->toBe('80.000');
});

it('makes both price Actions actor-first and self-authorizing', function () {
    $admin = ($this->makePricingActor)('admin');
    $superAdmin = ($this->makePricingActor)('super_admin');
    $course = Course::factory()->create(['default_price' => '100.000']);
    $batch = Batch::factory()->for($course)->create(['price' => null]);

    expect(fn () => app(UpdateCoursePriceAction::class)->execute($admin, $course, '200.000'))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateBatchPriceAction::class)->execute($admin, $batch, '150.000'))
        ->toThrow(AuthorizationException::class);

    $this->actingAs($superAdmin);
    app(UpdateCoursePriceAction::class)->execute($superAdmin, $course, '200.000');
    app(UpdateBatchPriceAction::class)->execute($superAdmin, $batch, '150.000');

    $courseActivity = Activity::query()
        ->where('subject_type', Course::class)
        ->where('subject_id', $course->getKey())
        ->where('event', 'updated')
        ->latest('id')
        ->firstOrFail();
    $batchActivity = Activity::query()
        ->where('subject_type', Batch::class)
        ->where('subject_id', $batch->getKey())
        ->where('event', 'updated')
        ->latest('id')
        ->firstOrFail();

    expect($course->fresh()?->default_price)->toBe('200.000')
        ->and($batch->fresh()?->price)->toBe('150.000')
        ->and((int) $courseActivity->causer_id)->toBe((int) $superAdmin->getKey())
        ->and((int) $batchActivity->causer_id)->toBe((int) $superAdmin->getKey());
});

it('attributes price changes to the explicit actor instead of the authenticated session', function () {
    $actor = ($this->makePricingActor)('super_admin');
    $otherUser = ($this->makePricingActor)('super_admin');
    $course = Course::factory()->create(['default_price' => '100.000']);
    $batch = Batch::factory()->for($course)->create(['price' => null]);

    auth()->logout();
    app(UpdateCoursePriceAction::class)->execute($actor, $course, '200.000');

    $this->actingAs($otherUser);
    app(UpdateBatchPriceAction::class)->execute($actor, $batch, '150.000');

    $courseActivity = Activity::query()
        ->where('subject_type', Course::class)
        ->where('subject_id', $course->getKey())
        ->where('event', 'updated')
        ->latest('id')
        ->firstOrFail();
    $batchActivity = Activity::query()
        ->where('subject_type', Batch::class)
        ->where('subject_id', $batch->getKey())
        ->where('event', 'updated')
        ->latest('id')
        ->firstOrFail();

    expect((int) $courseActivity->causer_id)->toBe((int) $actor->getKey())
        ->and((int) $batchActivity->causer_id)->toBe((int) $actor->getKey())
        ->and((int) $batchActivity->causer_id)->not->toBe((int) $otherUser->getKey());
});

it('keeps both price fields out of generic Filament persistence on create and edit', function () {
    $superAdmin = ($this->makePricingActor)('super_admin');
    $course = Course::factory()->create();
    $batch = Batch::factory()->for($course)->create();

    $components = [
        Livewire::actingAs($superAdmin)->test(CreateCourse::class)->instance()->form->getComponent('default_price'),
        Livewire::actingAs($superAdmin)->test(EditCourse::class, ['record' => $course->getKey()])->instance()->form->getComponent('default_price'),
        Livewire::actingAs($superAdmin)->test(CreateBatch::class)->instance()->form->getComponent('price'),
        Livewire::actingAs($superAdmin)->test(EditBatch::class, ['record' => $batch->getKey()])->instance()->form->getComponent('price'),
    ];

    expect($components)->each(
        fn ($component) => $component->not->toBeNull()
            ->and($component->value->isDehydrated())->toBeFalse(),
    );
});

it('does not invoke the course price Action for an equivalent decimal', function () {
    $course = Course::factory()->create([
        'name_en' => 'English B1',
        'default_price' => '100.000',
    ]);
    $savingEvents = 0;

    Course::saving(function (Course $saving) use ($course, &$savingEvents): void {
        if ($saving->is($course)) {
            $savingEvents++;
        }
    });

    Livewire::actingAs(($this->makePricingActor)('super_admin'))
        ->test(EditCourse::class, ['record' => $course->getKey()])
        ->fillForm([
            'name_en' => 'English B1 Evening',
            'default_price' => '100',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($course->fresh()?->name_en)->toBe('English B1 Evening')
        ->and($course->fresh()?->default_price)->toBe('100.000')
        ->and($savingEvents)->toBe(1);
});

it('keeps null and zero as different batch prices in both directions', function () {
    $batch = Batch::factory()->create(['price' => null]);
    $superAdmin = ($this->makePricingActor)('super_admin');

    Livewire::actingAs($superAdmin)
        ->test(EditBatch::class, ['record' => $batch->getKey()])
        ->fillForm(['price' => '0.000'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($batch->fresh()?->price)->toBe('0.000');

    Livewire::actingAs($superAdmin)
        ->test(EditBatch::class, ['record' => $batch->getKey()])
        ->fillForm(['price' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($batch->fresh()?->price)->toBeNull();
});

it('rejects excess precision instead of rounding it into an unchanged price', function () {
    $course = Course::factory()->create(['default_price' => '100.000']);

    Livewire::actingAs(($this->makePricingActor)('super_admin'))
        ->test(EditCourse::class, ['record' => $course->getKey()])
        ->fillForm(['default_price' => '100.0004'])
        ->call('save')
        ->assertHasFormErrors(['default_price']);

    expect($course->fresh()?->default_price)->toBe('100.000');
});

it('confines application price writes to the two pricing Actions', function () {
    $allowedWriters = array_map(static fn (string $path): string => strtolower(str_replace('\\', '/', $path)), [
        app_path('Domain/Finance/Actions/UpdateBatchPriceAction.php'),
        app_path('Domain/Finance/Actions/UpdateCoursePriceAction.php'),
    ]);
    $writePatterns = [
        '/(?:create|forceCreate|update|updateOrCreate|fill|forceFill|insert|upsert)\s*\(\s*\[(?:(?!\]\s*\)).)*[\'\"](?:default_price|price)[\'\"]\s*=>/s',
        '/->(?:default_price|price)\s*=(?!=)/',
        '/\[[\'\"](?:default_price|price)[\'\"]\]\s*=(?!=)/',
        '/setAttribute\s*\(\s*[\'\"](?:default_price|price)[\'\"]/',
    ];
    $violations = [];

    foreach (File::allFiles(app_path()) as $file) {
        $path = strtolower(str_replace('\\', '/', $file->getPathname()));

        if ($file->getExtension() !== 'php' || in_array($path, $allowedWriters, true)) {
            continue;
        }

        $source = $file->getContents();

        foreach ($writePatterns as $pattern) {
            if (preg_match($pattern, $source) === 1) {
                $violations[] = str_replace(app_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
                break;
            }
        }
    }

    expect($violations)->toBe([]);
});
