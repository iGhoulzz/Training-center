<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Models\StaffCertificate;
use App\Domain\Staff\Models\StaffProfile;
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

/*
|--------------------------------------------------------------------------
| A tripwire for common event-bypassing writes (P2-T12)
|--------------------------------------------------------------------------
|
| WHAT THIS IS, STATED HONESTLY: partial defence in depth, not a proof.
|
| `RecordsActivity` logs on Eloquent model events, so a financial mutation is
| absent from the append-only log if it never constructs a model — one
| `saveQuietly()`, one `DB::table('payments')->update(...)`, one builder chain
| — and nothing fails, because the test that would have caught it is the one
| nobody wrote for the new path. This scans for the shapes that do that, so it
| covers writes that do not exist yet, cheaply, at the moment they are added.
|
| WHAT IT CANNOT DO. Source patterns cannot decide whether a receiver is a
| model or a builder. The shape that defeats it is an assigned builder:
|
|     $query = Payment::query();
|     $query->update([...]);          // receiver is a variable; reads as an
|                                     // instance write; NOT reported
|
| Deciding that needs type inference, not a scan, and chasing it with more
| pattern complexity buys less than it costs — three revisions of this guard
| were each defeated by a shape the previous one had not imagined. So the gap
| is recorded here rather than papered over.
|
| WHAT ACTUALLY CARRIES THE GUARANTEE TODAY:
|
|   1. The behavioural tests. Nine finance test files assert `causer_id` on the
|      entry each mutation produces. Those prove the mutations they exercise
|      are logged, which is the real evidence.
|   2. A manual audit performed for this task: every `DB::table()` under
|      `app/Domain/Finance` is a read in the T10 report classes, and every
|      mutating call has a `$variable` receiver bar one allowlisted
|      export-progress write.
|   3. Writes going through Actions at all, which is enforced separately by
|      `ActionBoundaryArchTest`.
|
| This tripwire is a fourth, weaker line. Treat a failure here as a real
| finding; do not read a pass as proof the log cannot be bypassed.
|
| Reads are untouched: the T10 report classes are built on `DB::table()`
| deliberately, and `select`/aggregate queries are not mutations.
*/

it('finds no common event-bypassing write shape in the finance domain', function () {
    $writeShapes = [
        // Persist without firing events — the model-level bypass.
        'saveQuietly' => '/->saveQuietly\s*\(/',
        'updateQuietly' => '/->updateQuietly\s*\(/',
        'deleteQuietly' => '/->deleteQuietly\s*\(/',
        // Suppress events around an otherwise ordinary write.
        'withoutEvents' => '/::withoutEvents\s*\(/',
        // Query-builder writes never construct a model at all.
        'DB::table()->insert/update/delete' => '/DB::table\s*\([^)]*\)(?:[^;]*?)->\s*(?:insert|update|delete|upsert|increment|decrement)\s*\(/s',
        /*
         * A MUTATING CALL WHOSE RECEIVER ENDS IN `)` IS A BUILDER, NOT A MODEL.
         *
         * This rule is stated in the negative on purpose. The first two versions
         * tried to enumerate the ways a builder chain can START — `::query()`,
         * `->newQuery()`, `::where*()` — and cross-review pointed out that scope,
         * relationship and connection-builder writes all slip past such a list:
         *
         *     Charge::open()->update([...])                  // local scope
         *     $charge->payments()->update([...])             // relationship
         *     DB::connection('x')->table('y')->update([...]) // connection
         *
         * Predicting spellings is the wrong shape for this guard. Every mutating
         * write in this domain today has a `$variable` receiver, which is an
         * instance write and does fire `updating`/`updated`; a receiver ending in
         * `)` is the result of a call, which means a builder. Inverting the rule
         * covers the shapes above and the ones nobody has written yet.
         *
         * Eloquent's Builder::update() is `$this->toBase()->update(...)`
         * (Builder.php:1270-1272) — no model instance, no events. `delete`,
         * `upsert`, `increment` and `decrement` are the same.
         *
         * A method that returns a model and is then written to — `foo()->update()`
         * — is reported here too. That is deliberate: it is indistinguishable from
         * a builder at this level, and it is worth an explicit allowlist entry
         * saying which it is.
         */
        'builder-shaped write (receiver ends in `)`)' => '/\\)\\s*->\\s*(?:update|delete|insert|upsert|increment|decrement|forceDelete|truncate|restore)\\s*\\(/s',
        /*
         * Static mutators forward to the query builder without constructing a
         * model at all: Model::insert(), ::upsert(), ::destroy(), ::truncate().
         */
        'static mutator' => '/::\\s*(?:insert|insertOrIgnore|insertGetId|upsert|destroy|truncate)\\s*\\(/s',
    ];

    /*
     * Writes that skip model events on purpose, each with its reason.
     *
     * An entry here is a claim that the row written is not a financial record
     * the audit log is meant to carry. Anything else belongs in an Action.
     */
    $allowedEventlessWrites = [
        // Filament's own `exports` progress counters — a vendor bookkeeping
        // table for a queued job, not a financial record. The report data it
        // describes is frozen in the snapshot, not in this row.
        'app/Domain/Finance/Exports/PrepareReportCsvExport.php' => 'export progress counters',
    ];

    $offenders = [];

    foreach (File::allFiles(app_path('Domain/Finance')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $path = (string) $file->getRealPath();
        $source = appSourceWithoutComments($path);

        $relative = str_replace(
            [base_path().DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR],
            ['', '/'],
            $path,
        );

        if (array_key_exists($relative, $allowedEventlessWrites)) {
            continue;
        }

        foreach ($writeShapes as $name => $pattern) {
            if (preg_match($pattern, $source) === 1) {
                $offenders[] = $relative.' -> '.$name;
            }
        }
    }

    expect($offenders)->toBeEmpty(
        'A financial write matches a shape that bypasses Eloquent model events, so RecordsActivity '
        .'would never see it and the mutation would be missing from an append-only audit log. '
        .'Either route it through a model instance or add it to $allowedEventlessWrites with its '
        ."reason:\n".implode("\n", $offenders),
    );
});

it('detects each bypass shape this tripwire covers', function (string $sample) {
    /*
     * Without this, deleting a pattern above leaves a test that scans for
     * nothing and passes forever. Same reasoning as the localization
     * detector's own sample list.
     */
    $patterns = [
        '/->saveQuietly\s*\(/',
        '/->updateQuietly\s*\(/',
        '/->deleteQuietly\s*\(/',
        '/::withoutEvents\s*\(/',
        '/DB::table\s*\([^)]*\)(?:[^;]*?)->\s*(?:insert|update|delete|upsert|increment|decrement)\s*\(/s',
        '/\\)\\s*->\\s*(?:update|delete|insert|upsert|increment|decrement|forceDelete|truncate|restore)\\s*\\(/s',
        '/::\\s*(?:insert|insertOrIgnore|insertGetId|upsert|destroy|truncate)\\s*\\(/s',
    ];

    $matched = false;

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $sample) === 1) {
            $matched = true;
        }
    }

    expect($matched)->toBeTrue();
})->with([
    '$payment->saveQuietly();',
    '$charge->updateQuietly([\'amount\' => \'1.000\']);',
    '$line->deleteQuietly();',
    'Payment::withoutEvents(fn () => $payment->save());',
    'DB::table(\'payments\')->where(\'id\', 1)->update([\'reversed_at\' => now()]);',
    "DB::table('charges')\n    ->whereKey(1)\n    ->increment('amount');",
    // Eloquent builder writes — the shape this guard used to bless.
    "Payment::query()->whereKey(1)->update(['reversed_at' => now()]);",
    "Charge::query()->where('id', 1)->delete();",
    "Payment::where('reversed_at', null)->update(['reversed_at' => now()]);",
    "PayrollLine::query()\n    ->whereKey(1)\n    ->increment('amount');",
    // The four shapes cross-review named, none of which a chain-start list caught.
    "Charge::open()->update(['amount' => '1.000']);",
    "\$charge->payments()->update(['reversed_at' => now()]);",
    "DB::connection('mysql')->table('payments')->update(['reversed_at' => now()]);",
    "Payment::insert([['amount' => '1.000']]);",
    'Charge::destroy(1);',
    'PaymentAllocation::truncate();',
    /*
     * REPORTED ON PURPOSE, though both are instance writes that do fire events.
     * `foo()->update()` cannot be told apart from a builder chain by shape, and
     * guessing wrong in this direction is the safe way round: an over-report
     * costs one allowlist entry with a reason, an under-report costs a financial
     * mutation missing from an append-only log. The earlier version of this
     * guard tried to exempt these and, in doing so, exempted the real bypasses.
     */
    "Payment::query()->whereKey(1)->firstOrFail()->update(['notes' => 'x']);",
    'Charge::query()->findOrFail(1)->delete();',
]);

it('leaves reads and non-finance code alone', function (string $sample) {
    // The report classes read through DB::table() on purpose. A select or an
    // aggregate is not a mutation and must not be reported.
    $patterns = [
        '/DB::table\s*\([^)]*\)(?:[^;]*?)->\s*(?:insert|update|delete|upsert|increment|decrement)\s*\(/s',
        '/\\)\\s*->\\s*(?:update|delete|insert|upsert|increment|decrement|forceDelete|truncate|restore)\\s*\\(/s',
        '/::\\s*(?:insert|insertOrIgnore|insertGetId|upsert|destroy|truncate)\\s*\\(/s',
    ];

    foreach ($patterns as $pattern) {
        expect(preg_match($pattern, $sample))->toBe(0);
    }
})->with([
    "DB::table('charges')->select(['id', 'amount'])->get();",
    "DB::table('payment_allocations')->sum('amount');",
    "DB::table('payments')->where('reversed_at', null)->count();",
    /*
     * AN INSTANCE WRITE IS NOT A BYPASS and must not be reported: a `$variable`
     * receiver is a model, and $model->update() fires updating/updated, which is
     * exactly what RecordsActivity listens to. Every financial write in the
     * domain today has this shape.
     */
    "\$payment->update(['notes' => 'x']);",
    '\$charge->delete();',
    "\$this->export->update(['file_name' => 'x']);",
    "fn (): bool => \$charge->update(['amount' => '1.000']),",
    // A builder READ is not a write, and the report classes rely on this.
    "Payment::query()->where('reversed_at', null)->get();",
]);
