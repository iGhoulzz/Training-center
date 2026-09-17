<?php

declare(strict_types=1);

use App\Domain\Enrollment\Actions\CreateWithIdentifierCodeAction;
use App\Domain\Enrollment\Enums\BatchStatus;
use App\Domain\Enrollment\Enums\StudentStatus;
use App\Domain\Enrollment\Exceptions\IdentifierCodeAlreadyUsedException;
use App\Domain\Enrollment\Filament\Resources\BatchResource\Pages\CreateBatch;
use App\Domain\Enrollment\Filament\Resources\BatchResource\Pages\EditBatch;
use App\Domain\Enrollment\Filament\Resources\CourseResource\Pages\CreateCourse;
use App\Domain\Enrollment\Filament\Resources\StudentResource\Pages\CreateStudent;
use App\Domain\Enrollment\Filament\Resources\StudentResource\Pages\EditStudent;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Enrollment\Support\CertificateReference;
use App\Domain\Enrollment\Support\IdentifierCode;
use App\Domain\Finance\Filament\Pages\EnrollAndCollect;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\TextInput;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The generator: shape, alphabet and year
|--------------------------------------------------------------------------
|
| A picker is injected wherever the exact output matters, so each expectation
| is a literal rather than a re-derivation of the generator's own arithmetic.
*/

it('renders a student code as STU, the centre-local year and six characters', function () {
    $code = (new IdentifierCode(fn (int $max): int => 0))
        ->student(CarbonImmutable::parse('2026-07-01 09:00:00', 'UTC'));

    expect($code)->toBe('STU-2026-222222');
});

it('renders a batch code as BAT, the centre-local year and six characters', function () {
    $code = (new IdentifierCode(fn (int $max): int => 0))
        ->batch(CarbonImmutable::parse('2026-07-01 09:00:00', 'UTC'));

    expect($code)->toBe('BAT-2026-222222');
});

it('fits both columns', function () {
    $generator = new IdentifierCode;
    $at = CarbonImmutable::parse('2026-07-01 09:00:00', 'UTC');

    // students.student_code is varchar(30), batches.code varchar(40).
    expect(strlen($generator->student($at)))->toBe(15)
        ->and(strlen($generator->batch($at)))->toBe(15);
});

it('draws every suffix character from the transcription-safe certificate alphabet', function () {
    /*
     * A walk through indexes 0..5 names six distinct characters, so the
     * expectation is the first six letters of the shared alphabet — which
     * fails if this class carries a private alphabet of its own.
     */
    $index = 0;
    $code = (new IdentifierCode(function (int $max) use (&$index): int {
        return $index++;
    }))->student(CarbonImmutable::parse('2026-07-01 09:00:00', 'UTC'));

    expect($code)->toBe('STU-2026-'.substr(CertificateReference::ALPHABET, 0, 6));
});

it('offers the picker the whole alphabet and nothing beyond it', function () {
    $seen = [];

    (new IdentifierCode(function (int $max) use (&$seen): int {
        $seen[] = $max;

        return $max;
    }))->student(CarbonImmutable::parse('2026-07-01 09:00:00', 'UTC'));

    expect(array_unique($seen))->toBe([strlen(CertificateReference::ALPHABET) - 1]);
});

it('dates the code by the centre calendar, not UTC, across the new year', function () {
    /*
     * 22:30 UTC on 31 December is 00:30 on 1 January in Tripoli. A generator
     * reading UTC would mint 2026 for a record the centre created in 2027.
     */
    $generator = new IdentifierCode(fn (int $max): int => 0);
    $at = CarbonImmutable::parse('2026-12-31 22:30:00', 'UTC');

    expect($generator->student($at))->toBe('STU-2027-222222')
        ->and($generator->batch($at))->toBe('BAT-2027-222222');
});

it('uses real randomness when the container builds it', function () {
    $at = CarbonImmutable::parse('2026-07-01 09:00:00', 'UTC');
    $codes = collect(range(1, 20))->map(fn (): string => app(IdentifierCode::class)->student($at));

    // 31^6 combinations: twenty identical draws means no randomness at all.
    expect($codes->unique()->count())->toBeGreaterThan(1);
});

/*
|--------------------------------------------------------------------------
| The insert boundary: CreateWithIdentifierCodeAction
|--------------------------------------------------------------------------
|
| Every actor here holds exactly the one create permission the Action checks,
| never a role: a super admin holds Permission::all() and would pass whatever
| ability the Action authorized on.
*/

/** An account holding exactly the named permissions and nothing else. */
function identifierCodeActor(string ...$permissions): User
{
    if (! DB::table('permissions')->exists()) {
        test()->seed(RolePermissionSeeder::class);
    }

    $actor = User::factory()->create(['is_active' => true]);

    if ($permissions !== []) {
        $actor->givePermissionTo($permissions);
    }

    return $actor->fresh();
}

/** A generator whose every character is the alphabet's first, then its last. */
function identifierCodeCollidingThenFresh(int &$calls): IdentifierCode
{
    $last = strlen(CertificateReference::ALPHABET) - 1;

    return new IdentifierCode(function (int $max) use (&$calls, $last): int {
        $calls++;

        return $calls <= IdentifierCode::SUFFIX_LENGTH ? 0 : $last;
    });
}

/** @return array<string, mixed> */
function identifierCodeStudentAttributes(array $overrides = []): array
{
    return [
        'first_name' => 'Amal',
        'last_name' => 'Ibrahim',
        'status' => StudentStatus::Prospective,
        ...$overrides,
    ];
}

/** @return array<string, mixed> */
function identifierCodeBatchAttributes(array $overrides = []): array
{
    return [
        'course_id' => Course::factory()->create()->getKey(),
        'status' => BatchStatus::Planned,
        'capacity' => 20,
        'start_date' => '2026-09-01',
        'end_date' => '2026-12-01',
        ...$overrides,
    ];
}

const IDENTIFIER_CODE_STUDENT_PATTERN = '/^STU-\d{4}-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{6}$/';

const IDENTIFIER_CODE_BATCH_PATTERN = '/^BAT-\d{4}-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{6}$/';

it('generates a student code when none is given', function (?string $blank) {
    $actor = identifierCodeActor('create_student');

    $student = app(CreateWithIdentifierCodeAction::class)->createStudent(
        $actor,
        identifierCodeStudentAttributes(['student_code' => $blank]),
    );

    expect($student->exists)->toBeTrue()
        ->and(Student::query()->whereKey($student->getKey())->value('student_code'))
        ->toMatch(IDENTIFIER_CODE_STUDENT_PATTERN);
})->with([
    'null' => [null],
    'empty string' => [''],
    'whitespace' => ['   '],
]);

it('generates a batch code when none is given', function (?string $blank) {
    $actor = identifierCodeActor('create_batch');

    $batch = app(CreateWithIdentifierCodeAction::class)->createBatch(
        $actor,
        identifierCodeBatchAttributes(['code' => $blank]),
    );

    expect(Batch::query()->whereKey($batch->getKey())->value('code'))
        ->toMatch(IDENTIFIER_CODE_BATCH_PATTERN);
})->with([
    'null' => [null],
    'empty string' => [''],
    'whitespace' => ['   '],
]);

it('keeps a typed code exactly as typed, and never draws one', function () {
    $actor = identifierCodeActor('create_student', 'create_batch');
    $calls = 0;
    app()->instance(IdentifierCode::class, identifierCodeCollidingThenFresh($calls));

    $student = app(CreateWithIdentifierCodeAction::class)->createStudent(
        $actor,
        identifierCodeStudentAttributes(['student_code' => 'IMPORT-0042']),
    );
    $batch = app(CreateWithIdentifierCodeAction::class)->createBatch(
        $actor,
        identifierCodeBatchAttributes(['code' => 'ENG-B1-JAN27']),
    );

    expect($student->fresh()->student_code)->toBe('IMPORT-0042')
        ->and($batch->fresh()->code)->toBe('ENG-B1-JAN27')
        ->and($calls)->toBe(0);
});

it('refuses an actor without the create permission before writing', function () {
    $actor = identifierCodeActor('create_batch');
    $other = identifierCodeActor('create_student');

    expect(fn () => app(CreateWithIdentifierCodeAction::class)->createStudent($actor, identifierCodeStudentAttributes()))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(CreateWithIdentifierCodeAction::class)->createBatch($other, identifierCodeBatchAttributes()))
        ->toThrow(AuthorizationException::class)
        ->and(Student::query()->count())->toBe(0)
        ->and(Batch::query()->count())->toBe(0);
});

it('redraws a generated code that collides with an existing one', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00:00', 'UTC'));
    $actor = identifierCodeActor('create_student', 'create_batch');
    Student::factory()->create(['student_code' => 'STU-2026-222222']);
    Batch::factory()->create(['code' => 'BAT-2026-222222']);

    $studentCalls = 0;
    app()->instance(IdentifierCode::class, identifierCodeCollidingThenFresh($studentCalls));
    $student = app(CreateWithIdentifierCodeAction::class)->createStudent($actor, identifierCodeStudentAttributes());

    $batchCalls = 0;
    app()->instance(IdentifierCode::class, identifierCodeCollidingThenFresh($batchCalls));
    $batch = app(CreateWithIdentifierCodeAction::class)->createBatch($actor, identifierCodeBatchAttributes());

    // One colliding draw, then one fresh draw: twelve picks, never more.
    expect($student->fresh()->student_code)->toBe('STU-2026-ZZZZZZ')
        ->and($studentCalls)->toBe(12)
        ->and($batch->fresh()->code)->toBe('BAT-2026-ZZZZZZ')
        ->and($batchCalls)->toBe(12);
});

it('refuses a typed code already in use rather than silently replacing it', function () {
    $actor = identifierCodeActor('create_student', 'create_batch');
    Student::factory()->create(['student_code' => 'IMPORT-0042']);
    Batch::factory()->create(['code' => 'ENG-B1-JAN27']);

    $calls = 0;
    app()->instance(IdentifierCode::class, identifierCodeCollidingThenFresh($calls));

    expect(fn () => app(CreateWithIdentifierCodeAction::class)->createStudent(
        $actor,
        identifierCodeStudentAttributes(['student_code' => 'IMPORT-0042', 'first_name' => 'Second']),
    ))->toThrow(IdentifierCodeAlreadyUsedException::class)
        ->and(fn () => app(CreateWithIdentifierCodeAction::class)->createBatch(
            $actor,
            identifierCodeBatchAttributes(['code' => 'ENG-B1-JAN27']),
        ))->toThrow(IdentifierCodeAlreadyUsedException::class)
        ->and($calls)->toBe(0)
        ->and(Student::query()->where('first_name', 'Second')->exists())->toBeFalse()
        ->and(Batch::query()->count())->toBe(1);
});

it('rethrows an unrelated unique violation on the same table unchanged, after a single draw', function () {
    /*
     * students_user_id_unique, not students_student_code_unique. Retrying it
     * would redraw five perfectly good codes and then report exhaustion — a
     * lie about which constraint refused. The index-name guard is what tells
     * the two apart; remove it and this test sees IdentifierCodeExhaustedException.
     */
    $actor = identifierCodeActor('create_student');
    $portalUser = User::factory()->create();
    Student::factory()->create(['user_id' => $portalUser->getKey()]);

    $calls = 0;
    app()->instance(IdentifierCode::class, identifierCodeCollidingThenFresh($calls));

    $thrown = null;

    try {
        app(CreateWithIdentifierCodeAction::class)->createStudent(
            $actor,
            identifierCodeStudentAttributes(['user_id' => $portalUser->getKey()]),
        );
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(UniqueConstraintViolationException::class)
        ->and($thrown->index)->toBe('students_user_id_unique')
        ->and($calls)->toBe(IdentifierCode::SUFFIX_LENGTH)
        ->and(Student::query()->count())->toBe(1);
});

it('logs the real generated code on creation and never anything else', function () {
    $actor = identifierCodeActor('create_student');

    $student = app(CreateWithIdentifierCodeAction::class)->createStudent($actor, identifierCodeStudentAttributes());

    $entries = Activity::query()
        ->where('subject_type', $student->getMorphClass())
        ->where('subject_id', $student->getKey())
        ->get();

    // One entry: the insert. A placeholder-then-replace would add an update.
    expect($entries)->toHaveCount(1)
        ->and($entries->first()->event)->toBe('created')
        ->and($entries->first()->causer_id)->toBe($actor->getKey())
        // v5 keeps the diff in attribute_changes, not properties (ActivityLogTest:70).
        ->and($entries->first()->attribute_changes?->get('attributes')['student_code'] ?? null)
        ->toBe($student->fresh()->student_code)
        ->toMatch(IDENTIFIER_CODE_STUDENT_PATTERN);
});

it('takes the code year and the stored timestamps from one reading of the clock', function () {
    /*
     * EVERY CLOCK READING HERE IS ONE YEAR LATER THAN THE LAST, starting at
     * 22:30 UTC on 31 December — 00:30 on 1 January in Tripoli. Any two
     * readings therefore disagree about the year, so a code minted from one
     * reading and a created_at stamped by Eloquent from another cannot agree
     * by luck.
     *
     * With one captured instant, the stored UTC date is 31 December of some
     * year Y and the code, dated by the centre calendar, carries Y + 1.
     */
    $actor = identifierCodeActor('create_student');
    $readings = 0;

    Carbon::setTestNow(function () use (&$readings): CarbonImmutable {
        return CarbonImmutable::parse('2026-12-31 22:30:00', 'UTC')->addYears($readings++);
    });

    try {
        $student = app(CreateWithIdentifierCodeAction::class)->createStudent($actor, identifierCodeStudentAttributes());
    } finally {
        Carbon::setTestNow();
    }

    $row = DB::table('students')->where('id', $student->getKey())->first(['student_code', 'created_at', 'updated_at']);
    $storedYear = (int) substr((string) $row->created_at, 0, 4);

    expect(substr((string) $row->created_at, 5))->toBe('12-31 22:30:00')
        ->and($row->updated_at)->toBe($row->created_at)
        ->and($row->student_code)->toStartWith('STU-'.($storedYear + 1).'-');
});
/*
|--------------------------------------------------------------------------
| The three callers: blank generates, typed stands, refusals land on the field
|--------------------------------------------------------------------------
|
| Each creation path is driven through its real component. A refusal must
| arrive as a field error carrying the shared translated message — never a raw
| driver exception, and never a silently replaced code.
|
| The typed-code race is staged with a `creating` listener that commits the
| same code a moment before the insert: after the form's own unique rule has
| already passed, which is exactly the window that rule cannot see.
*/

/** An active account holding one role, assigned through the trusted system path. */
function identifierCodeRoleUser(string $role): User
{
    if (! DB::table('permissions')->exists()) {
        test()->seed(RolePermissionSeeder::class);
    }

    $user = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($user, $role);

    return $user->fresh();
}

/** Commit $code to another student row just before the next student insert. */
function identifierCodeStageStudentRace(string $code): void
{
    Student::creating(function () use ($code): void {
        DB::table('students')->insert([
            'student_code' => $code,
            'first_name' => 'Raced',
            'last_name' => 'Elsewhere',
            'status' => 'prospective',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });
}

/** Commit $code to another batch row just before the next batch insert. */
function identifierCodeStageBatchRace(string $code, int $courseId): void
{
    Batch::creating(function () use ($code, $courseId): void {
        DB::table('batches')->insert([
            'course_id' => $courseId,
            'code' => $code,
            'status' => 'planned',
            'capacity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });
}

/** Every generated draw is the alphabet's first character, forever. */
function identifierCodeAlwaysColliding(): void
{
    app()->instance(IdentifierCode::class, new IdentifierCode(fn (int $max): int => 0));
}

/** Sentinel copy, so the assertions prove the shared keys are what render. */
function identifierCodeSentinelLines(): void
{
    Lang::addLines([
        'enrollment.identifier_code_hint' => 'SENTINEL-HINT',
        'enrollment.identifier_code_already_used' => 'SENTINEL-ALREADY-USED',
        'enrollment.identifier_code_exhausted' => 'SENTINEL-EXHAUSTED',
        'enrollment.course_code_placeholder' => 'SENTINEL-COURSE-PLACEHOLDER',
    ], app()->getLocale());
}

it('generates a code on the student create page when the field is left blank', function () {
    $staff = identifierCodeRoleUser('staff');

    Livewire::actingAs($staff)
        ->test(CreateStudent::class)
        ->fillForm(['first_name' => 'Walk', 'last_name' => 'In'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Student::query()->sole()->student_code)->toMatch(IDENTIFIER_CODE_STUDENT_PATTERN);
});

it('generates a code on the batch create page and still writes the price afterwards', function () {
    $owner = identifierCodeRoleUser('super_admin');
    $course = Course::factory()->create();

    Livewire::actingAs($owner)
        ->test(CreateBatch::class)
        ->fillForm([
            'course_id' => $course->getKey(),
            'status' => BatchStatus::Planned->value,
            'capacity' => 20,
            'price' => '325.250',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $batch = Batch::query()->sole();

    expect($batch->code)->toMatch(IDENTIFIER_CODE_BATCH_PATTERN)
        ->and($batch->price)->toBe('325.250');
});

it('generates a code for a walk-in created from Enrol & Collect', function () {
    $this->actingAs(identifierCodeRoleUser('staff'));

    $id = EnrollAndCollect::createStudent(['first_name' => 'New', 'last_name' => 'Walkin', 'phone' => '0910000000']);

    $student = Student::query()->findOrFail($id);

    expect($student->student_code)->toMatch(IDENTIFIER_CODE_STUDENT_PATTERN)
        ->and($student->status)->toBe(StudentStatus::Prospective);
});

it('shows both code refusals on the student create page as a field error', function (string $case, string $message) {
    /*
     * BOTH CASES ON EACH PAGE. The page catches two exceptions; testing one
     * per page left the other catch removable with the suite green (Codex's
     * review of #62). Each case below fails if its own exception is dropped
     * from CreateStudent::handleRecordCreation().
     */
    identifierCodeSentinelLines();
    $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00:00', 'UTC'));
    $data = ['first_name' => 'Typed', 'last_name' => 'Import'];

    if ($case === 'typed race') {
        identifierCodeStageStudentRace('IMPORT-0042');
        $data['student_code'] = 'IMPORT-0042';
    } else {
        Student::factory()->create(['student_code' => 'STU-2026-222222']);
        identifierCodeAlwaysColliding();
    }

    $component = Livewire::actingAs(identifierCodeRoleUser('staff'))
        ->test(CreateStudent::class)
        ->fillForm($data)
        ->call('create')
        ->assertHasFormErrors(['student_code']);

    expect($component->instance()->getErrorBag()->first('data.student_code'))->toBe($message)
        ->and(Student::query()->where('first_name', 'Typed')->exists())->toBeFalse();
})->with([
    'typed race' => ['typed race', 'SENTINEL-ALREADY-USED'],
    'exhausted generation' => ['exhausted generation', 'SENTINEL-EXHAUSTED'],
]);

it('shows both code refusals on the batch create page as a field error', function (string $case, string $message) {
    identifierCodeSentinelLines();
    $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00:00', 'UTC'));
    $course = Course::factory()->create();
    $data = [
        'course_id' => $course->getKey(),
        'status' => BatchStatus::Planned->value,
        'capacity' => 20,
    ];

    if ($case === 'typed race') {
        identifierCodeStageBatchRace('ENG-B1-JAN27', (int) $course->getKey());
        $data['code'] = 'ENG-B1-JAN27';
    } else {
        Batch::factory()->for($course)->create(['code' => 'BAT-2026-222222', 'capacity' => 0]);
        identifierCodeAlwaysColliding();
    }

    $component = Livewire::actingAs(identifierCodeRoleUser('admin'))
        ->test(CreateBatch::class)
        ->fillForm($data)
        ->call('create')
        ->assertHasFormErrors(['code']);

    /*
     * The attempted batch — the only one with capacity 20 — was not created.
     * Not a row count: the staged race inserts on this same connection inside
     * the page's transaction, so the page's rollback removes it too.
     */
    expect($component->instance()->getErrorBag()->first('data.code'))->toBe($message)
        ->and(Batch::query()->where('capacity', 20)->exists())->toBeFalse();
})->with([
    'typed race' => ['typed race', 'SENTINEL-ALREADY-USED'],
    'exhausted generation' => ['exhausted generation', 'SENTINEL-EXHAUSTED'],
]);

it('generates a code through the real Enrol & Collect quick-create modal when nothing is typed', function () {
    /*
     * Through the modal, not createStudent() directly: the modal's own field
     * rules run first, so a `required()` restored on its student_code field
     * fails here — and nowhere else, because every direct call skips them.
     */
    $component = Livewire::actingAs(identifierCodeRoleUser('admin'))
        ->test(EnrollAndCollect::class)
        ->callAction(TestAction::make('createOption')->schemaComponent('student_id'), data: [
            'first_name' => 'Blank',
            'last_name' => 'Walkin',
        ])
        ->assertHasNoActionErrors();

    $student = Student::query()->sole();

    expect($student->student_code)->toMatch(IDENTIFIER_CODE_STUDENT_PATTERN)
        // Livewire state carries the Select's key as a string.
        ->and($component->get('data.student_id'))->toBe((string) $student->getKey());
});

it('shows a quick-create refusal in Enrol & Collect on the modal field, with the shared message', function (string $case, string $message) {
    identifierCodeSentinelLines();
    $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00:00', 'UTC'));

    $data = ['first_name' => 'Typed', 'last_name' => 'Walkin'];

    if ($case === 'typed race') {
        identifierCodeStageStudentRace('IMPORT-0042');
        $data['student_code'] = 'IMPORT-0042';
    } else {
        Student::factory()->create(['student_code' => 'STU-2026-222222']);
        identifierCodeAlwaysColliding();
    }

    $component = Livewire::actingAs(identifierCodeRoleUser('admin'))
        ->test(EnrollAndCollect::class)
        ->callAction(TestAction::make('createOption')->schemaComponent('student_id'), data: $data)
        ->assertHasActionErrors(['student_code']);

    /*
     * The message, not merely the key: a `required` error lands on the same
     * field, so a key-only assertion passes for the wrong reason whenever
     * the field stops accepting a blank.
     */
    expect($component->instance()->getErrorBag()->first('mountedActions.0.data.student_code'))->toBe($message)
        ->and(Student::query()->where('first_name', 'Typed')->exists())->toBeFalse();
})->with([
    'typed race' => ['typed race', 'SENTINEL-ALREADY-USED'],
    'exhausted generation' => ['exhausted generation', 'SENTINEL-EXHAUSTED'],
]);

it('still requires the code when editing, so a blank edit cannot reach the NOT NULL column', function () {
    $admin = identifierCodeRoleUser('admin');
    $student = Student::factory()->create();
    $batch = Batch::factory()->create();

    Livewire::actingAs($admin)
        ->test(EditStudent::class, ['record' => $student->getKey()])
        ->fillForm(['student_code' => null])
        ->call('save')
        ->assertHasFormErrors(['student_code' => 'required']);

    Livewire::actingAs($admin)
        ->test(EditBatch::class, ['record' => $batch->getKey()])
        ->fillForm(['code' => null])
        ->call('save')
        ->assertHasFormErrors(['code' => 'required']);
});

it('tells the operator a blank code is generated, on all three creation forms', function () {
    identifierCodeSentinelLines();
    $admin = identifierCodeRoleUser('admin');

    $hint = fn (TextInput $field): bool => $field->getPlaceholder() === 'SENTINEL-HINT';

    Livewire::actingAs($admin)->test(CreateStudent::class)->assertFormFieldExists('student_code', $hint);
    Livewire::actingAs($admin)->test(CreateBatch::class)->assertFormFieldExists('code', $hint);
    Livewire::actingAs($admin)
        ->test(EnrollAndCollect::class)
        ->mountAction(TestAction::make('createOption')->schemaComponent('student_id'))
        ->assertFormFieldExists('student_code', 'mountedActionSchema0', $hint);
});

it('suggests a course code shape without ever filling one in', function () {
    identifierCodeSentinelLines();

    Livewire::actingAs(identifierCodeRoleUser('admin'))
        ->test(CreateCourse::class)
        ->assertFormFieldExists('code', fn (TextInput $field): bool => $field->getPlaceholder() === 'SENTINEL-COURSE-PLACEHOLDER')
        ->assertFormSet(['code' => null])
        ->fillForm(['name_en' => 'No code typed', 'total_hours' => 10])
        ->call('create')
        ->assertHasFormErrors(['code' => 'required']);

    expect(Course::query()->count())->toBe(0);
});
