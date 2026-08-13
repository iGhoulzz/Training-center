<?php

declare(strict_types=1);

use App\Domain\Finance\Actions\WriteOffChargeAction;
use App\Domain\Finance\Data\WriteOffChargeData;
use App\Domain\Finance\Exceptions\ChargeAlreadyWrittenOffException;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Support\ChargeBalance;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Filament\Resources\ActivityResource;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| WriteOffChargeAction — retiring a debt the centre will never collect
|--------------------------------------------------------------------------
|
| Design section 4: super admin only, a mandatory reason, nothing erased, and a
| second write-off refused outright rather than silently re-stamping a new
| actor and timestamp over an earlier decision. Section 4 also settles that
| `create_charge`, `update_charge` and `delete_charge` are deliberately not
| seeded and that ChargePolicy refuses them unconditionally — proven here the
| way ActivityAppendOnlyTest proves the same shape for the activity log: grant
| the permission anyway and show the policy still says no.
*/
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->system = app(SystemRoleWriter::class);

    $this->actorWith = function (string $role): User {
        $user = User::factory()->create(['is_active' => true]);
        $this->system->assignRoles($user, $role);

        return $user->refresh();
    };

    $this->superAdmin = ($this->actorWith)('super_admin');

    // readFor('charge') in RolePermissionSeeder: view_any_charge and
    // view_charge only.
    $this->admin = ($this->actorWith)('admin');

    $this->writeOff = app(WriteOffChargeAction::class);
});

/*
|--------------------------------------------------------------------------
| All three columns, together
|--------------------------------------------------------------------------
*/

it('writes off a charge, setting all three write-off columns together', function () {
    $this->travelTo('2026-08-12 09:00:00');

    $charge = Charge::factory()->create(['amount' => '750.000']);

    $result = $this->writeOff->execute($this->superAdmin, new WriteOffChargeData(
        (int) $charge->getKey(),
        'The centre has accepted this debt will never be collected.',
    ));

    expect($result->written_off_at)->not->toBeNull()
        ->and((int) $result->written_off_by)->toBe((int) $this->superAdmin->getKey())
        ->and($result->written_off_reason)->toBe('The centre has accepted this debt will never be collected.');

    // Read back through the query builder, the same discipline EnrollmentTest
    // uses for the ENR- reference — the model holds what the Action assigned
    // in memory, the row holds what actually committed. A CHECK constraint
    // requires all three or none, so partial persistence would fail loudly
    // rather than quietly, but the values themselves are worth reading back.
    $stored = DB::table('charges')->where('id', $charge->getKey())->first();

    expect($stored->written_off_at)->not->toBeNull()
        ->and((int) $stored->written_off_by)->toBe((int) $this->superAdmin->getKey())
        ->and($stored->written_off_reason)->toBe('The centre has accepted this debt will never be collected.');
});

/*
|--------------------------------------------------------------------------
| A mandatory reason
|--------------------------------------------------------------------------
*/

it('refuses a write-off with no reason', function () {
    $charge = Charge::factory()->create();

    expect(fn () => new WriteOffChargeData((int) $charge->getKey(), ''))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => new WriteOffChargeData((int) $charge->getKey(), '   '))
        ->toThrow(InvalidArgumentException::class);

    expect($charge->fresh()->isWrittenOff())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The debt stays visible — nothing is erased
|--------------------------------------------------------------------------
*/

it('keeps the debt and its amount visible after it is written off', function () {
    $charge = Charge::factory()->create(['amount' => '620.000']);
    $studentId = (int) $charge->enrollment->student_id;

    $this->writeOff->execute($this->superAdmin, new WriteOffChargeData(
        (int) $charge->getKey(),
        'Uncollectable after repeated attempts to reach the student.',
    ));

    $fresh = Charge::query()->find($charge->getKey());

    expect($fresh)->not->toBeNull()
        ->and($fresh->amount)->toBe('620.000')
        ->and($fresh->isWrittenOff())->toBeTrue();

    // Writing off is not paying: ChargeBalance deliberately does not subtract
    // it, so the balance itself is unmoved (design section 4).
    expect(ChargeBalance::outstandingFor((int) $charge->getKey())->toDecimal())->toBe('620.000');

    // The student's history still shows it — queried by the owning student,
    // not merely by the charge's own id, the row is still there in full.
    $stillInHistory = Charge::query()
        ->whereHas('enrollment', fn ($query) => $query->where('student_id', $studentId))
        ->find($charge->getKey());

    expect($stillInHistory)->not->toBeNull()
        ->and($stillInHistory->amount)->toBe('620.000');
});

/*
|--------------------------------------------------------------------------
| A second write-off is refused, not silently re-stamped
|--------------------------------------------------------------------------
*/

it('refuses to write off an already written-off charge, leaving the original decision untouched', function () {
    $this->travelTo('2026-08-01 09:00:00');

    $charge = Charge::factory()->create(['amount' => '500.000']);

    $first = $this->writeOff->execute($this->superAdmin, new WriteOffChargeData(
        (int) $charge->getKey(),
        'First decision: the centre will not collect this.',
    ));

    $originalAt = $first->written_off_at;
    $originalBy = (int) $first->written_off_by;
    $originalReason = $first->written_off_reason;

    // Time passes, and a different super admin tries a second time.
    $this->travelTo('2026-08-05 09:00:00');
    $secondSuperAdmin = ($this->actorWith)('super_admin');

    $thrown = null;

    try {
        $this->writeOff->execute($secondSuperAdmin, new WriteOffChargeData(
            (int) $charge->getKey(),
            'A second, later attempt at the same charge.',
        ));
    } catch (ChargeAlreadyWrittenOffException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(ChargeAlreadyWrittenOffException::class)
        ->and($thrown->chargeId)->toBe((int) $charge->getKey());

    $fresh = $charge->fresh();

    expect($fresh->written_off_at->equalTo($originalAt))->toBeTrue()
        ->and((int) $fresh->written_off_by)->toBe($originalBy)
        ->and($fresh->written_off_reason)->toBe($originalReason)
        ->and((int) $fresh->written_off_by)->not->toBe((int) $secondSuperAdmin->getKey());
});

/*
|--------------------------------------------------------------------------
| The audit entry names the Action's actor, never the session
|--------------------------------------------------------------------------
|
| `written_off_by` is set from the actor this Action authorized. The activity
| entry has to agree with it, or the row and the append-only log tell two
| different stories about who decided a debt was uncollectable — and the log is
| the one design section 4 treats as the audit record.
|
| Spatie resolves a causer from the authenticated session unless it is told
| otherwise, so both failure modes below are real: a console invocation with no
| session records nobody, and an invocation while somebody else holds the
| session records the wrong person. The T2 pricing and discount Actions already
| pass their actor through CauserResolver for exactly this reason;
| DiscountDefinitionTest carries the same two-sided assertion.
*/

/**
 * The causer recorded against this charge's most recent update entry.
 *
 * Null means the entry named nobody at all, which is a distinct failure from
 * naming the wrong person — the two tests below separate them deliberately, so
 * one run reports both rather than short-circuiting on the first.
 */
function writeOffCauserId(Charge $charge): ?int
{
    $causerId = Activity::query()
        ->where('subject_type', Charge::class)
        ->where('subject_id', $charge->getKey())
        ->where('event', 'updated')
        ->latest('id')
        ->value('causer_id');

    return $causerId === null ? null : (int) $causerId;
}

it('attributes the write-off to the Action actor when no session exists at all', function () {
    // A console or queued invocation: nobody is signed in, and the entry must
    // still name the actor this Action authorized.
    $charge = Charge::factory()->create(['amount' => '400.000']);

    $this->writeOff->execute($this->superAdmin, new WriteOffChargeData(
        (int) $charge->getKey(),
        'Written off with no session, the way a console invocation runs.',
    ));

    expect(writeOffCauserId($charge))->toBe((int) $this->superAdmin->getKey());
});

it('attributes the write-off to the Action actor while a different user holds the session', function () {
    $this->actingAs($this->admin);

    $charge = Charge::factory()->create(['amount' => '400.000']);

    $this->writeOff->execute($this->superAdmin, new WriteOffChargeData(
        (int) $charge->getKey(),
        'Written off by a super admin while an admin holds the session.',
    ));

    expect(writeOffCauserId($charge))->toBe((int) $this->superAdmin->getKey())
        ->and(writeOffCauserId($charge))->not->toBe((int) $this->admin->getKey());

    // The row and the log have to tell the same story about who decided this.
    expect((int) $charge->fresh()->written_off_by)->toBe(writeOffCauserId($charge));
});

it('puts the reason and both other write-off columns in the diff a reader opens', function () {
    $charge = Charge::factory()->create(['amount' => '900.000']);

    $this->writeOff->execute($this->superAdmin, new WriteOffChargeData(
        (int) $charge->getKey(),
        'Student left the country; the centre will not pursue this.',
    ));

    $entry = Activity::query()
        ->where('subject_type', Charge::class)
        ->where('subject_id', $charge->getKey())
        ->where('event', 'updated')
        ->latest('id')
        ->firstOrFail();

    // describeChanges() is what ActivityResource actually renders, so asserting
    // its output is asserting what a reader is shown. No manual entry is built
    // by this Action, and none needs to be: all three columns are audited (see
    // the Action's docblock). Asserted on the field names and the operator's own
    // words, never on the translated framing around them.
    $changes = ActivityResource::describeChanges($entry);

    expect($changes)->toContain('written_off_reason')
        ->and($changes)->toContain('Student left the country; the centre will not pursue this.')
        ->and($changes)->toContain('written_off_at')
        ->and($changes)->toContain('written_off_by');
});

/*
|--------------------------------------------------------------------------
| An admin cannot write off, invoked directly
|--------------------------------------------------------------------------
*/

it('refuses an admin, even holding every seeded charge read permission', function () {
    $charge = Charge::factory()->create();

    expect($this->admin->can('view_any_charge'))->toBeTrue()
        ->and($this->admin->can('view_charge'))->toBeTrue();

    $thrown = null;

    try {
        $this->writeOff->execute($this->admin, new WriteOffChargeData(
            (int) $charge->getKey(),
            'An admin attempting a write-off they do not hold write_off_charge for.',
        ));
    } catch (AuthorizationException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(AuthorizationException::class)
        ->and($charge->fresh()->isWrittenOff())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| create(), update() and delete() refuse even when the permission is granted
|--------------------------------------------------------------------------
|
| THE ASSERTION THAT PROVES THE POLICY IGNORES THE GRANT (ActivityAppendOnlyTest
| pattern). Production never seeds create_charge, update_charge or
| delete_charge — design section 4 — but a permission that does not exist
| proves nothing about a policy: the refusal could just as easily be "nobody
| holds it". So the permissions are created HERE, granted to the strongest
| actor there is, and the refusal asserted anyway.
*/

it('refuses create, update and delete for a super admin even when the permission is granted', function () {
    $charge = Charge::factory()->create();

    foreach (['create_charge', 'update_charge', 'delete_charge'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $this->superAdmin->givePermissionTo(['create_charge', 'update_charge', 'delete_charge']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $superAdmin = $this->superAdmin->refresh();

    expect($superAdmin->can('create_charge'))->toBeTrue()
        ->and($superAdmin->can('update_charge'))->toBeTrue()
        ->and($superAdmin->can('delete_charge'))->toBeTrue();

    expect(Gate::forUser($superAdmin)->allows('create', Charge::class))->toBeFalse()
        ->and(Gate::forUser($superAdmin)->allows('update', $charge))->toBeFalse()
        ->and(Gate::forUser($superAdmin)->allows('delete', $charge))->toBeFalse();
});
