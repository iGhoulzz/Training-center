<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Models\StaffCertificate;
use App\Domain\Staff\Models\StaffProfile;
use App\Domain\Staff\Support\RecordsActivity;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Support\ActivityBuffer;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->system = app(SystemRoleWriter::class);

    $this->admin = User::factory()->create(['is_active' => true]);
    $this->system->assignRoles($this->admin, 'admin');
    $this->admin->refresh();

    /** The most recent entry, whatever wrote it. */
    $this->latest = fn (): ?Activity => Activity::query()->latest('id')->first();

    /** Entries for one subject, newest first. */
    $this->entriesFor = fn (object $subject) => Activity::query()
        ->where('subject_type', $subject::class)
        ->where('subject_id', $subject->getKey())
        ->latest('id')
        ->get();
});

/*
|--------------------------------------------------------------------------
| The model-event boundary
|--------------------------------------------------------------------------
*/

it('logs an ordinary Eloquent create with the acting user', function () {
    // Not through an Action — a bare factory create. The boundary is the model
    // event, so a Filament form, a console command and an Action all log alike.
    $this->actingAs($this->admin);

    $student = Student::factory()->create();

    $activity = ($this->latest)();

    expect($activity)->not->toBeNull()
        ->and($activity->event)->toBe('created')
        ->and($activity->subject_type)->toBe(Student::class)
        ->and((int) $activity->subject_id)->toBe((int) $student->getKey())
        ->and((int) $activity->causer_id)->toBe((int) $this->admin->getKey());
});

it('records a field-level before and after on update', function () {
    $this->actingAs($this->admin);

    $student = Student::factory()->create(['first_name' => 'Amal']);
    $student->update(['first_name' => 'Amel']);

    // v5 keeps the diff in attribute_changes, NOT in properties as v4 did.
    $changes = ($this->latest)()->attribute_changes;

    expect($changes->get('old')['first_name'])->toBe('Amal')
        ->and($changes->get('attributes')['first_name'])->toBe('Amel');
});

it('logs deletion, and logs a force delete exactly once', function () {
    /*
     * PINS THE REAL BEHAVIOUR RATHER THAN ASSUMING IT.
     *
     * forceDelete() calls delete() internally, so `deleted` fires and v5 records
     * it — which is why no forceDeleted listener exists. Adding one would log the
     * same removal twice. This asserts the count, so either mistake fails.
     */
    $this->actingAs($this->admin);

    $student = Student::factory()->create();
    $before = Activity::query()->count();

    $student->forceDelete();

    $deletions = Activity::query()
        ->where('subject_type', Student::class)
        ->where('subject_id', $student->getKey())
        ->where('event', 'deleted')
        ->count();

    expect($deletions)->toBe(1)
        ->and(Activity::query()->count())->toBe($before + 1);
});

it('audits role rows themselves, not only who holds them', function () {
    /*
     * Shield's resource creates, renames and deletes roles through ordinary
     * Eloquent writes that never reach SyncUserRolesAction or
     * UpdateRolePermissionsAction. Without the concern on App\Models\Role, the
     * authorization graph is rewritable with nothing in the log.
     */
    $this->actingAs($this->admin);

    $role = Role::create(['name' => 'registrar', 'guard_name' => 'web']);
    $role->update(['name' => 'senior_registrar']);

    $events = ($this->entriesFor)($role)->pluck('event')->all();

    expect($events)->toContain('created')
        ->and($events)->toContain('updated');

    $rename = ($this->entriesFor)($role)->firstWhere('event', 'updated');

    expect($rename->attribute_changes->get('old')['name'])->toBe('registrar')
        ->and($rename->attribute_changes->get('attributes')['name'])->toBe('senior_registrar');

    $role->delete();

    expect(($this->entriesFor)($role)->pluck('event')->all())->toContain('deleted');
});

it('audits both locale columns on a course independently', function () {
    // There is no `name` column — Course::name() resolves against the request
    // locale. The audited facts are the stored name_en and name_ar.
    $this->actingAs($this->admin);

    $course = Course::factory()->create(['name_en' => 'English B1']);
    $course->update(['name_ar' => 'الإنجليزية ب1']);

    $changes = ($this->latest)()->attribute_changes;

    expect($changes->get('attributes'))->toHaveKey('name_ar')
        ->and($changes->get('attributes'))->not->toHaveKey('name_en');
});

/*
|--------------------------------------------------------------------------
| Secrets never reach a diff
|--------------------------------------------------------------------------
*/

it('never records a password or remember token in a diff', function () {
    /*
     * THE RUNTIME PROOF, covering both layers at once.
     *
     * Neither layer alone makes this fail: the global exclusion list is merged
     * OVER the model allowlist and wins, so naming `password` in the allowlist
     * changes nothing while the global list still holds it. Only removing it
     * from both exposes the hash — verified by mutation.
     *
     * That is why the two layers also get their own direct assertions below.
     * This test proves the outcome; those prove each control is still in place.
     */
    $this->actingAs($this->admin);

    $target = User::factory()->create();
    $target->update(['password' => 'a-brand-new-secret', 'name' => 'Renamed']);

    $changes = ($this->latest)()->attribute_changes;

    $everything = json_encode($changes->all());

    expect($changes->get('attributes'))->toHaveKey('name')
        ->and($changes->get('attributes'))->not->toHaveKey('password')
        ->and($changes->get('attributes'))->not->toHaveKey('remember_token')
        ->and($everything)->not->toContain('a-brand-new-secret');
});

it('pins the global exclusion list', function () {
    /*
     * A BACKSTOP THAT CANNOT BE PROVEN BY THE TEST ABOVE.
     *
     * With per-model allowlists in place, emptying default_except_attributes
     * exposes nothing — so the diff test stays green and the config could rot
     * unnoticed until a model is switched to logFillable(). Pinned directly.
     */
    expect(config('activitylog.default_except_attributes'))
        ->toContain('password')
        ->toContain('remember_token');
});

/**
 * Every model that records activity, found rather than listed.
 *
 * @return array<int, class-string<Model>>
 */
function recordsActivityModels(): array
{
    $classes = [];

    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $class = 'App\\'.str_replace(
            [app_path().DIRECTORY_SEPARATOR, '.php', DIRECTORY_SEPARATOR],
            ['', '', '\\'],
            (string) $file->getRealPath(),
        );

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            continue;
        }

        if (in_array(RecordsActivity::class, class_uses_recursive($class), true)) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}

it('keeps secrets out of every model allowlist', function () {
    /*
     * The layer the runtime test above cannot isolate.
     *
     * default_except_attributes wins over an allowlist, so adding `password` to
     * User::auditedAttributes() leaks nothing today — and would leak everything
     * the day somebody trims the global list. Asserted directly so the two
     * controls fail independently rather than only in combination.
     *
     * THE MODEL LIST IS DERIVED (P1-T15, group 3 finding L4). It used to be five
     * class names written out by hand while eight models used the trait, and the
     * omissions were not harmless: StaffCertificate is the one that owns `disk`,
     * `path` and `original_filename`, and an uploaded filename routinely carries
     * somebody's name or national ID. A hand-written list protects whichever
     * models were remembered on the day and silently stops covering every model
     * added afterwards.
     */
    $secrets = ['password', 'remember_token'];
    $models = recordsActivityModels();

    /*
     * The scan must have found something, and specifically the model the old
     * list forgot. Without this the loop below passes vacuously the moment the
     * derivation breaks — an empty array satisfies every assertion inside it.
     */
    expect($models)->not->toBeEmpty(
        'No model was found using RecordsActivity, so nothing below was actually checked.',
    )->and($models)->toContain(StaffCertificate::class);

    foreach ($models as $model) {
        $named = array_intersect((new $model)->auditedAttributes(), $secrets);

        expect($named)->toBeEmpty(
            $model.' names a credential in its audit allowlist: '.implode(', ', $named),
        );
    }
});

it('does not log a login timestamp as a user change', function () {
    // last_login_at is absent from the allowlist AND written with saveQuietly().
    // Either alone suppresses the noise; both are cheap next to a log where every
    // real change is buried under sign-ins.
    $before = Activity::query()->where('subject_type', User::class)->count();

    $this->admin->forceFill(['last_login_at' => now()])->saveQuietly();

    expect(Activity::query()->where('subject_type', User::class)->count())->toBe($before);
});

/*
|--------------------------------------------------------------------------
| Context: actor, IP, timestamp
|--------------------------------------------------------------------------
*/

it('attaches the request IP to a model-generated entry', function () {
    $this->actingAs($this->admin);

    Student::factory()->create();

    $activity = ($this->latest)();

    expect($activity->getProperty('ip'))->toBe(request()->ip())
        ->and($activity->created_at)->not->toBeNull();
});

it('attaches the request IP to an explicit entry that has no subject', function () {
    /*
     * The case a model's beforeActivityLogged() hook structurally cannot cover:
     * that hook fires on the SUBJECT, and this entry has none. It is exactly the
     * kind of entry — a failed sign-in — where the IP matters most.
     */
    activity('auth')->event('login_failed')->log('login_failed');

    expect(($this->latest)()->getProperty('ip'))->toBe(request()->ip());
});

it('records a null actor and a null IP for genuine system work', function () {
    /*
     * A console command has no request and no session. Recording null is the
     * honest answer; inventing 127.0.0.1 would make a seeder indistinguishable
     * from somebody working locally.
     */
    $this->app['request']->server->remove('REMOTE_ADDR');

    $user = User::factory()->create();
    $this->system->assignRoles($user, 'staff');

    $entry = Activity::query()->where('event', 'roles_changed')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBeNull()
        ->and($entry->getProperty('ip'))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Rollback
|--------------------------------------------------------------------------
*/

it('creates no audit entry when the surrounding transaction rolls back', function () {
    /*
     * THE GUARANTEE BUFFERING WOULD BREAK.
     *
     * With buffering off each entry save()s inline, inside the open transaction,
     * so a rollback takes it. With buffering on, the entry sits in memory and is
     * flushed on terminating/shutdown — outside the transaction — leaving a row
     * that claims a write which never committed.
     *
     * THE EXPLICIT FLUSH IS LOAD-BEARING. Nothing in a test reaches `terminating`,
     * so without it enabling buffering would leave this green: the row would
     * simply never be written during the test. Flushing forces the buffered
     * entry out, which is what makes the mutation visible.
     */
    $before = Activity::query()->count();

    try {
        DB::transaction(function (): void {
            Student::factory()->create();

            throw new RuntimeException('the domain write failed');
        });
    } catch (RuntimeException) {
        // Expected.
    }

    app(ActivityBuffer::class)->flush();

    expect(Activity::query()->count())->toBe($before);
});

it('keeps buffering disabled and unswitchable', function () {
    // Not env()-driven: a per-environment flip would silently trade the rollback
    // guarantee for query throughput.
    expect(config('activitylog.buffer.enabled'))->toBeFalse();

    $config = file_get_contents(config_path('activitylog.php'));

    expect($config)->not->toContain('ACTIVITYLOG_BUFFER_ENABLED');
});

/*
|--------------------------------------------------------------------------
| Storage paths stay out
|--------------------------------------------------------------------------
*/

it('never records a storage path or uploaded filename', function () {
    // A path is not an audit fact and a filename routinely carries somebody's
    // name or national ID. The photo lifecycle is a semantic event instead.
    $this->actingAs($this->admin);

    $profile = StaffProfile::factory()->create();
    $profile->update(['profile_photo_path' => 'staff-photos/secret-name-scan.png']);

    $everything = Activity::query()->get()->map(
        fn (Activity $entry): string => (string) json_encode([
            $entry->attribute_changes?->all(),
            $entry->properties?->all(),
        ]),
    )->implode(' ');

    expect($everything)->not->toContain('secret-name-scan')
        ->and($everything)->not->toContain('staff-photos/');
});
