<?php

declare(strict_types=1);

use App\Domain\Finance\Actions\CreateDiscountAction;
use App\Domain\Finance\Actions\DeactivateDiscountAction;
use App\Domain\Finance\Actions\DeleteDiscountAction;
use App\Domain\Finance\Exceptions\DiscountInUseException;
use App\Domain\Finance\Filament\Resources\DiscountResource;
use App\Domain\Finance\Filament\Resources\DiscountResource\Pages\CreateDiscount;
use App\Domain\Finance\Filament\Resources\DiscountResource\Pages\ListDiscounts;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Discount;
use App\Domain\Finance\Policies\DiscountPolicy;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\CreateAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->makeDiscountActor = function (string $role): User {
        $actor = User::factory()->create(['is_active' => true]);
        app(SystemRoleWriter::class)->assignRoles($actor, $role);

        return $actor->refresh();
    };
});

it('lets an admin read discount definitions but only a super admin create them', function () {
    $discount = Discount::factory()->create();
    $admin = ($this->makeDiscountActor)('admin');
    $staff = ($this->makeDiscountActor)('staff');
    $superAdmin = ($this->makeDiscountActor)('super_admin');

    $this->actingAs($staff)->get('/admin/discounts')->assertForbidden();
    $this->actingAs($staff)->get("/admin/discounts/{$discount->getKey()}")->assertForbidden();
    $this->actingAs($staff)->get('/admin/discounts/create')->assertForbidden();

    $this->actingAs($admin)->get('/admin/discounts')->assertSuccessful();
    $this->actingAs($admin)->get("/admin/discounts/{$discount->getKey()}")->assertSuccessful();
    $this->actingAs($admin)->get('/admin/discounts/create')->assertForbidden();

    $list = Livewire::actingAs($admin)->test(ListDiscounts::class);
    $list->assertActionHidden('create')
        ->assertTableActionHidden('deactivate', $discount)
        ->assertTableActionHidden('delete', $discount);

    $list->call('mountTableAction', 'deactivate', $discount->getKey());
    expect($list->get('mountedActions'))->toBeEmpty()
        ->and($discount->fresh()?->is_active)->toBeTrue();

    $createAction = Livewire::actingAs($superAdmin)
        ->test(ListDiscounts::class)
        ->instance()
        ->getAction('create');

    expect($createAction)->not->toBeInstanceOf(CreateAction::class);
});

it('creates a discount through its Action-backed page and audits the actor', function () {
    $superAdmin = ($this->makeDiscountActor)('super_admin');

    Livewire::actingAs($superAdmin)
        ->test(CreateDiscount::class)
        ->fillForm([
            'name' => 'Early enrollment 10%',
            'percentage' => '10.00',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $discount = Discount::firstOrFail();
    $activity = Activity::query()
        ->where('subject_type', Discount::class)
        ->where('subject_id', $discount->getKey())
        ->where('event', 'created')
        ->firstOrFail();

    expect($discount->name)->toBe('Early enrollment 10%')
        ->and($discount->percentage)->toBe('10.00')
        ->and($discount->is_active)->toBeTrue()
        ->and((int) $activity->causer_id)->toBe((int) $superAdmin->getKey());
});

it('attributes every discount lifecycle event to the explicit actor', function () {
    $actor = ($this->makeDiscountActor)('super_admin');
    $otherUser = ($this->makeDiscountActor)('super_admin');

    auth()->logout();
    $discount = app(CreateDiscountAction::class)->execute($actor, 'Audited discount', '12.50');

    $this->actingAs($otherUser);
    app(DeactivateDiscountAction::class)->execute($actor, $discount);
    app(DeleteDiscountAction::class)->execute($actor, $discount);

    $causerIds = Activity::query()
        ->where('subject_type', Discount::class)
        ->where('subject_id', $discount->getKey())
        ->whereIn('event', ['created', 'updated', 'deleted'])
        ->orderBy('id')
        ->pluck('causer_id')
        ->map(static fn ($causerId): int => (int) $causerId)
        ->all();

    expect($causerIds)->toBe([
        (int) $actor->getKey(),
        (int) $actor->getKey(),
        (int) $actor->getKey(),
    ])->not->toContain((int) $otherUser->getKey());
});

it('makes every discount lifecycle Action self-authorizing', function () {
    $admin = ($this->makeDiscountActor)('admin');
    $discount = Discount::factory()->create();

    expect(fn () => app(CreateDiscountAction::class)->execute($admin, 'Denied 10%', '10.00'))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeactivateDiscountAction::class)->execute($admin, $discount))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteDiscountAction::class)->execute($admin, $discount))
        ->toThrow(AuthorizationException::class);

    expect($discount->fresh()?->is_active)->toBeTrue()
        ->and(Discount::where('name', 'Denied 10%')->exists())->toBeFalse();
});

it('validates percentage precision and range inside the create Action', function (string $percentage) {
    $superAdmin = ($this->makeDiscountActor)('super_admin');

    expect(fn () => app(CreateDiscountAction::class)->execute($superAdmin, 'Invalid discount', $percentage))
        ->toThrow(ValidationException::class);

    expect(Discount::count())->toBe(0);
})->with(['0.00', '100.01', '10.001']);

it('deactivates a definition without changing issued charges and audits the actor', function () {
    $superAdmin = ($this->makeDiscountActor)('super_admin');
    $discount = Discount::factory()->create(['percentage' => '30.00']);
    $charge = Charge::factory()->withDiscount($discount)->create();

    Livewire::actingAs($superAdmin)
        ->test(ListDiscounts::class)
        ->callTableAction('deactivate', $discount);

    $activity = Activity::query()
        ->where('subject_type', Discount::class)
        ->where('subject_id', $discount->getKey())
        ->where('event', 'updated')
        ->latest('id')
        ->firstOrFail();

    expect($discount->fresh()?->is_active)->toBeFalse()
        ->and($charge->fresh()?->discount_percentage)->toBe('30.00')
        ->and((int) $activity->causer_id)->toBe((int) $superAdmin->getKey());
});

it('deletes and recreates an unused definition', function () {
    $superAdmin = ($this->makeDiscountActor)('super_admin');
    $discount = Discount::factory()->create([
        'name' => 'Mistyped discount',
        'percentage' => '10.00',
    ]);

    Livewire::actingAs($superAdmin)
        ->test(ListDiscounts::class)
        ->callTableAction('delete', $discount);

    $deletedActivity = Activity::query()
        ->where('subject_type', Discount::class)
        ->where('subject_id', $discount->getKey())
        ->where('event', 'deleted')
        ->firstOrFail();
    $replacement = app(CreateDiscountAction::class)->execute($superAdmin, 'Mistyped discount', '15.00');

    expect(Discount::count())->toBe(1)
        ->and($replacement->percentage)->toBe('15.00')
        ->and((int) $deletedActivity->causer_id)->toBe((int) $superAdmin->getKey());
});

it('converts the database refusal for a used definition into the typed exception', function () {
    $superAdmin = ($this->makeDiscountActor)('super_admin');
    $discount = Discount::factory()->create();
    Charge::factory()->withDiscount($discount)->create();

    try {
        app(DeleteDiscountAction::class)->execute($superAdmin, $discount);
        $this->fail('The database allowed a referenced discount to be deleted.');
    } catch (DiscountInUseException $exception) {
        expect($exception->discountId)->toBe((int) $discount->getKey());
    }

    expect(Discount::whereKey($discount->getKey())->exists())->toBeTrue();

    Livewire::actingAs($superAdmin)
        ->test(ListDiscounts::class)
        ->callTableAction('delete', $discount)
        ->assertNotified(__('pricing.discount_in_use'));

    expect(Discount::whereKey($discount->getKey())->exists())->toBeTrue();
});

it('confines every discount lifecycle write to its designated Action', function () {
    Permission::findOrCreate('update_discount');
    $superAdmin = ($this->makeDiscountActor)('super_admin');
    $superAdmin->givePermissionTo('update_discount');
    $discount = Discount::factory()->create();

    expect($superAdmin->can('update', $discount))->toBeFalse()
        ->and(DiscountResource::getPages())->not->toHaveKey('edit');

    $allowedWriters = [
        'create' => strtolower(str_replace('\\', '/', app_path('Domain/Finance/Actions/CreateDiscountAction.php'))),
        'deactivate' => strtolower(str_replace('\\', '/', app_path('Domain/Finance/Actions/DeactivateDiscountAction.php'))),
        'delete' => strtolower(str_replace('\\', '/', app_path('Domain/Finance/Actions/DeleteDiscountAction.php'))),
    ];
    $operationPatterns = [
        'create' => [
            '/\bnew\s+Discount\b/',
            '/\bDiscount::(?:(?:query|newQuery)\(\)->)?(?:create|createQuietly|forceCreate|forceCreateQuietly'
                .'|make|insert|insertOrIgnore|insertGetId|insertUsing|insertOrIgnoreUsing|upsert'
                .'|firstOrCreate|firstOrNew|createOrFirst|updateOrCreate|incrementOrCreate)\s*\(/',
            '/\bDB::table\s*\(\s*[\'\"]discounts[\'\"]\s*\)\s*->\s*'
                .'(?:insert|insertOrIgnore|insertGetId|insertUsing|insertOrIgnoreUsing|upsert)\s*\(/',
        ],
        'immutable' => [
            '/(?:update|fill|forceFill)\s*\(\s*\[(?:(?!\]\s*\)).)*[\'\"](?:name|percentage)[\'\"]\s*=>/s',
            '/->(?:name|percentage)\s*=(?!=)/',
            '/setAttribute\s*\(\s*[\'\"](?:name|percentage)[\'\"]/',
        ],
        'reactivate' => [
            '/[\'\"]is_active[\'\"]\s*=>\s*(?:true|1)\b/',
            '/->is_active\s*=\s*(?:true|1)\b/',
            '/setAttribute\s*\(\s*[\'\"]is_active[\'\"]\s*,\s*(?:true|1)\b/',
        ],
        'deactivate' => [
            '/[\'\"]is_active[\'\"]\s*=>\s*(?:false|0)\b/',
            '/->is_active\s*=\s*(?:false|0)\b/',
            '/setAttribute\s*\(\s*[\'\"]is_active[\'\"]\s*,\s*(?:false|0)\b/',
        ],
        'delete' => [
            '/->\s*(?:delete|forceDelete)\s*\(\s*\)/',
            '/\bDiscount::(?:(?:query|newQuery)\(\)->)?(?:destroy|forceDestroy)\s*\(/',
            '/\bDB::table\s*\(\s*[\'\"]discounts[\'\"]\s*\).*->\s*delete\s*\(/s',
        ],
    ];
    $violations = [];

    foreach (File::allFiles(app_path()) as $file) {
        $path = strtolower(str_replace('\\', '/', $file->getPathname()));

        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = appSourceWithoutComments($file->getPathname());

        if (preg_match('/\bDiscount\b|discounts/', $source) !== 1) {
            continue;
        }

        foreach ($operationPatterns as $operation => $patterns) {
            $allowedPath = $allowedWriters[$operation] ?? null;

            if ($path === $allowedPath || ($operation === 'reactivate' && $path === $allowedWriters['create'])) {
                continue;
            }

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $source) === 1) {
                    $violations[] = $operation.': '.str_replace(app_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
                    break;
                }
            }
        }
    }

    expect($violations)->toBe([]);
});

it('keeps permanently refused discount policy operations closed even when permission is granted', function (string $method, string $permission) {
    Permission::findOrCreate($permission);
    $superAdmin = ($this->makeDiscountActor)('super_admin');
    $superAdmin->givePermissionTo($permission);
    $discount = Discount::factory()->create();
    $arguments = in_array($method, ['deleteAny', 'restoreAny', 'forceDeleteAny', 'reorder'], true)
        ? [$superAdmin]
        : [$superAdmin, $discount];

    expect(app(DiscountPolicy::class)->{$method}(...$arguments))->toBeFalse();
})->with([
    'update' => ['update', 'update_discount'],
    'bulk delete' => ['deleteAny', 'delete_any_discount'],
    'restore' => ['restore', 'restore_discount'],
    'bulk restore' => ['restoreAny', 'restore_any_discount'],
    'force delete' => ['forceDelete', 'force_delete_discount'],
    'bulk force delete' => ['forceDeleteAny', 'force_delete_any_discount'],
    'replicate' => ['replicate', 'replicate_discount'],
    'reorder' => ['reorder', 'reorder_discount'],
]);

it('recognizes representative attempts to bypass the discount lifecycle boundary', function (string $operation, string $source) {
    $patterns = [
        'create' => '/\bDiscount::(?:(?:query|newQuery)\(\)->)?(?:create|createQuietly|forceCreate|forceCreateQuietly'
            .'|make|insert|insertOrIgnore|insertGetId|insertUsing|insertOrIgnoreUsing|upsert'
            .'|firstOrCreate|firstOrNew|createOrFirst|updateOrCreate|incrementOrCreate)\s*\(/',
        'reactivate' => '/[\'\"]is_active[\'\"]\s*=>\s*(?:true|1)\b/',
        'deactivate' => '/[\'\"]is_active[\'\"]\s*=>\s*(?:false|0)\b/',
        'delete' => '/->\s*(?:delete|forceDelete)\s*\(\s*\)/',
    ];

    expect(preg_match($patterns[$operation], $source))->toBe(1);
})->with([
    'direct create' => ['create', "Discount::create(['name' => 'Bypass']);"],
    'query builder create' => ['create', "Discount::query()->create(['name' => 'Bypass']);"],
    'direct insert' => ['create', "Discount::insert([['name' => 'Bypass']]);"],
    'query builder insert' => ['create', "Discount::query()->insert([['name' => 'Bypass']]);"],
    'insert or ignore' => ['create', "Discount::insertOrIgnore([['name' => 'Bypass']]);"],
    'insert and return id' => ['create', "Discount::query()->insertGetId(['name' => 'Bypass']);"],
    'upsert' => ['create', "Discount::upsert([['name' => 'Bypass']], ['name']);"],
    'quiet create' => ['create', "Discount::createQuietly(['name' => 'Bypass']);"],
    'quiet forced create' => ['create', "Discount::forceCreateQuietly(['name' => 'Bypass']);"],
    'reactivation' => ['reactivate', "\$discount->update(['is_active' => true]);"],
    'deactivation' => ['deactivate', "\$discount->update(['is_active' => false]);"],
    'deletion' => ['delete', '\$discount->delete();'],
]);
