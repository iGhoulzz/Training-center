<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;
use Throwable;

/**
 * Add a heading an expense can be recorded against.
 *
 * Actor first and self-authorizing, like every other request-path Action: the
 * Filament create page is one caller, and any other would be held to the same
 * rules. A new category is always active; switching one off is an edit, owned by
 * `UpdateExpenseCategoryAction`.
 *
 * A LOST RACE IS A FIELD ERROR, NEVER A 500
 * ------------------------------------------
 * `rules()` checks the name is free, but two concurrent saves can both pass that
 * read. `expense_categories_name_en_unique` is the backstop, and the loser's
 * unique violation is turned into the same `name_en` error the validator gives
 * (`duplicateNameRefusal()`), so the person sees "already taken" and not an error
 * page. Any other unique violation is not ours and propagates.
 *
 * THE AUDIT TRAIL IS THE MODEL EVENT. The `created` entry is written by
 * RecordsActivity; this Action only names the actor it is attributed to.
 */
final class CreateExpenseCategoryAction
{
    public const NAME_MAX_LENGTH = 100;

    /** Laravel's generated name for `$table->string('name_en')->unique()`. */
    public const NAME_INDEX = 'expense_categories_name_en_unique';

    /** MySQL ER_DUP_ENTRY, checked beside the index name as the other lost-race handlers do. */
    private const DUPLICATE_ENTRY = 1062;

    public function __construct(private readonly CauserResolver $causers) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function execute(User $actor, string $nameEn, ?string $nameAr): ExpenseCategory
    {
        Gate::forUser($actor)->authorize('create', ExpenseCategory::class);

        $validated = Validator::make(
            self::normalise($nameEn, $nameAr),
            self::rules(),
            [],
            self::attributeNames(),
        )->validate();

        try {
            return $this->causers->withCauser(
                $actor,
                fn (): ExpenseCategory => ExpenseCategory::query()->create([
                    'name_en' => (string) $validated['name_en'],
                    'name_ar' => $validated['name_ar'] ?? null,
                    'is_active' => true,
                ]),
            );
        } catch (UniqueConstraintViolationException $exception) {
            throw self::duplicateNameRefusal($exception);
        }
    }

    /**
     * What to throw for a unique violation: the validator's own "already taken"
     * error on `name_en` when it was the name index that collided, otherwise the
     * violation itself, untouched.
     *
     * Shared with `UpdateExpenseCategoryAction`, so a lost race refuses in the same
     * words on both paths.
     */
    public static function duplicateNameRefusal(UniqueConstraintViolationException $exception): Throwable
    {
        if (($exception->errorInfo[1] ?? null) !== self::DUPLICATE_ENTRY || $exception->index !== self::NAME_INDEX) {
            return $exception;
        }

        return ValidationException::withMessages([
            'name_en' => trans('validation.unique', ['attribute' => self::attributeNames()['name_en']]),
        ]);
    }

    /**
     * Trimmed, with a blank Arabic name stored as "not translated".
     *
     * A blank is null rather than '' so name() falls back to the English text; an
     * empty string would read as a translation that is empty.
     *
     * @return array{name_en: string, name_ar: ?string}
     */
    public static function normalise(string $nameEn, ?string $nameAr): array
    {
        $arabic = $nameAr === null ? '' : trim($nameAr);

        return [
            'name_en' => trim($nameEn),
            'name_ar' => $arabic === '' ? null : $arabic,
        ];
    }

    /**
     * The rules shared with UpdateExpenseCategoryAction, so the two cannot drift
     * apart. The English name is unique across categories (a case-insensitive
     * match under the table's collation): two "Rent" headings would make the
     * picker a guess.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(?int $ignoreId = null): array
    {
        return [
            'name_en' => [
                'required',
                'string',
                'max:'.self::NAME_MAX_LENGTH,
                Rule::unique(ExpenseCategory::class, 'name_en')->ignore($ignoreId),
            ],
            'name_ar' => ['nullable', 'string', 'max:'.self::NAME_MAX_LENGTH],
        ];
    }

    /**
     * Field names as a person reads them, so a refusal names "Name (English)" and
     * not "name_en".
     *
     * @return array<string, string>
     */
    public static function attributeNames(): array
    {
        return [
            'name_en' => __('expenses.category_name_en'),
            'name_ar' => __('expenses.category_name_ar'),
        ];
    }
}
