<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Actions;

use App\Domain\Enrollment\Exceptions\IdentifierCodeAlreadyUsedException;
use App\Domain\Enrollment\Exceptions\IdentifierCodeExhaustedException;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Enrollment\Support\IdentifierCode;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * The single insert boundary for students and batches (P35-T09).
 *
 * THREE CALLERS, ONE WRITE
 * ------------------------
 * CreateStudent, CreateBatch and EnrollAndCollect's quick-create all delegate
 * here, and IdentifierCodeArchTest keeps it that way. A fourth writer that
 * created these rows directly would silently skip generation, which is exactly
 * the drift one boundary exists to prevent. Factories and seeders are not
 * application writers and remain free to build explicit fixtures.
 *
 * BLANK GENERATES, TYPED STANDS
 * -----------------------------
 * The owner's decision: codes are generated, but the field stays editable,
 * because importing an existing centre's records is the one case where a
 * manual code is right. A typed code is an ordinary audited attribute and is
 * never redrawn.
 *
 * ONE INSTANT, CAPTURED BEFORE THE LOOP
 * -------------------------------------
 * Both columns are NOT NULL, so the code must exist before the insert — which
 * is before Eloquent would stamp `created_at`. Letting Eloquent stamp it would
 * be a second clock reading, and CertificateReference already records what two
 * readings do across Tripoli's new year: a code dated one year on a row created
 * in the other. So one UTC instant is taken here, handed to the generator, and
 * written to `created_at` and `updated_at` explicitly on every attempt.
 *
 * RETRY IS DISCRIMINATED BY INDEX NAME, AND BY WHO CHOSE THE VALUE
 * ----------------------------------------------------------------
 * IssueStudentCertificateAction's rule, extended by one question. A unique
 * violation on the CODE index means:
 *
 *   - a GENERATED code collided: nothing about the request was wrong, so
 *     redraw, bounded by MAX_ATTEMPTS;
 *   - a TYPED code collided: the operator's value is taken, so refuse with
 *     IdentifierCodeAlreadyUsedException and never substitute one.
 *
 * A violation on ANY OTHER index — `students_user_id_unique` is the live
 * example — is rethrown unchanged on the first attempt. Retrying it would draw
 * five good codes and then report exhaustion, naming the wrong constraint.
 */
final class CreateWithIdentifierCodeAction
{
    /** MySQL ER_DUP_ENTRY — belt and braces with catching UniqueConstraintViolationException specifically. */
    private const DUPLICATE_ENTRY = 1062;

    /** Laravel's default `{table}_{column}_unique`, as the students migration creates it. */
    public const STUDENT_CODE_UNIQUE_INDEX = 'students_student_code_unique';

    /** Laravel's default `{table}_{column}_unique`, as the batches migration creates it. */
    public const BATCH_CODE_UNIQUE_INDEX = 'batches_code_unique';

    /** A backstop against a broken picker, not a limit genuine collisions approach. */
    public const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly IdentifierCode $codes,
        private readonly CauserResolver $causers,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  fillable student attributes; a blank `student_code` is generated
     *
     * @throws AuthorizationException if the actor may not create students.
     * @throws IdentifierCodeAlreadyUsedException if a typed code is already taken.
     * @throws IdentifierCodeExhaustedException if every generated code collided.
     */
    public function createStudent(User $actor, array $attributes): Student
    {
        Gate::forUser($actor)->authorize('create', Student::class);

        return $this->create(
            $actor,
            new Student,
            $attributes,
            'student_code',
            self::STUDENT_CODE_UNIQUE_INDEX,
            fn (CarbonImmutable $createdAt): string => $this->codes->student($createdAt),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes  fillable batch attributes; a blank `code` is generated
     *
     * @throws AuthorizationException if the actor may not create batches.
     * @throws IdentifierCodeAlreadyUsedException if a typed code is already taken.
     * @throws IdentifierCodeExhaustedException if every generated code collided.
     */
    public function createBatch(User $actor, array $attributes): Batch
    {
        Gate::forUser($actor)->authorize('create', Batch::class);

        return $this->create(
            $actor,
            new Batch,
            $attributes,
            'code',
            self::BATCH_CODE_UNIQUE_INDEX,
            fn (CarbonImmutable $createdAt): string => $this->codes->batch($createdAt),
        );
    }

    /**
     * @template TModel of Model
     *
     * @param  TModel  $prototype  an unsaved instance naming the model to create
     * @param  array<string, mixed>  $attributes
     * @param  Closure(CarbonImmutable): string  $mint
     * @return TModel
     */
    private function create(
        User $actor,
        Model $prototype,
        array $attributes,
        string $column,
        string $codeIndex,
        Closure $mint,
    ): Model {
        $typed = self::typedCode($attributes[$column] ?? null);
        unset($attributes[$column]);

        $createdAt = CarbonImmutable::now('UTC');

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $code = $typed ?? $mint($createdAt);

            try {
                return DB::transaction(fn (): Model => $this->causers->withCauser(
                    $actor,
                    function () use ($prototype, $attributes, $column, $code, $createdAt): Model {
                        $model = $prototype->newInstance($attributes);
                        $model->setAttribute($column, $code);
                        $model->setCreatedAt($createdAt);
                        $model->setUpdatedAt($createdAt);
                        $model->save();

                        return $model;
                    },
                ));
            } catch (UniqueConstraintViolationException $exception) {
                if (! self::collidedOn($exception, $codeIndex)) {
                    throw $exception;
                }

                if ($typed !== null) {
                    throw new IdentifierCodeAlreadyUsedException($typed);
                }
            }
        }

        throw new IdentifierCodeExhaustedException($column, self::MAX_ATTEMPTS);
    }

    /** The operator's code, or null when the field was left blank. */
    private static function typedCode(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function collidedOn(UniqueConstraintViolationException $exception, string $index): bool
    {
        return ($exception->errorInfo[1] ?? null) === self::DUPLICATE_ENTRY
            && $exception->index === $index;
    }
}
