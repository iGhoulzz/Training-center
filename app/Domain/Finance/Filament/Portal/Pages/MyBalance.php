<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Portal\Pages;

use App\Domain\Enrollment\Support\AuthenticatedStudent;
use App\Domain\Finance\Data\EnrollmentBalance;
use App\Domain\Finance\Services\StudentBalanceQuery;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * "My balance" (design section 4).
 *
 * CONSUMES T9's BULK QUERY, NEVER outstandingForEnrollment() PER ROW.
 * ---------------------------------------------------------------------
 * `StudentBalanceQuery::forStudent()` is T9's answer to design section 4.2:
 * every enrolment a student holds, billed or not, with its outstanding figure
 * and the total, in a fixed number of statements — proven independently by
 * StudentBalanceQueryTest and, from this page's own side, by
 * PortalQueryCountTest. This page calls it exactly once in mount() and does
 * no further Finance query of its own.
 *
 * P4-T05 adds course names and batch codes through EnrollmentQueryService's
 * published catalogue projection. The page chooses the localized course name,
 * with the same Arabic-empty fallback as Course::name(), without querying models.
 *
 * PLAIN ARRAYS OF DECIMAL STRINGS, NOT Money OBJECTS, ON THE PUBLIC
 * PROPERTY.
 * -------------------------------------------------------------------------
 * `Money` has no `__toString()` on purpose (see its own docblock) and
 * Livewire would have nothing sensible to serialise it as. `toDecimal()` is
 * called once in mount() and the bare digit string is what `$rows` and
 * `$total` carry — the same "call toDecimal() and mean it" discipline every
 * other money-displaying page in this codebase follows.
 *
 * NOTHING DERIVED IS STORED. `$rows` and `$total` are Livewire's ordinary
 * per-request component state, not a database write — CLAUDE.md non-negotiable
 * 2 is about columns and tables, and none exists here. mount() runs this
 * query fresh on every page load; nothing about a balance is ever cached
 * into a row.
 */
class MyBalance extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 1;

    protected string $view = 'portal.my-balance';

    /**
     * One entry per enrolment the student holds, in the order
     * StudentBalanceQuery returned them (enrolment id ascending).
     *
     * @var array<int, array{enrollment_id: int, course: string, batch_code: string, outstanding: string}>
     */
    public array $rows = [];

    /** The total across every row, as a bare decimal string — see the class docblock. */
    public string $total = '0.000';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view_own_balance') ?? false;
    }

    public function getTitle(): string
    {
        return __('portal.my_balance_title');
    }

    public static function getNavigationLabel(): string
    {
        return __('portal.my_balance_navigation_label');
    }

    public function mount(): void
    {
        $student = app(AuthenticatedStudent::class)->resolve();

        $summary = app(StudentBalanceQuery::class)->forStudent((int) $student->getKey());

        $this->rows = collect($summary->enrollments)
            ->map(fn (EnrollmentBalance $balance): array => [
                'enrollment_id' => $balance->enrollmentId,
                'course' => app()->getLocale() === 'ar' && filled($balance->courseNameAr)
                    ? (string) $balance->courseNameAr : $balance->courseNameEn,
                'batch_code' => $balance->batchCode,
                'outstanding' => $balance->outstanding->toDecimal(),
            ])
            ->values()
            ->all();

        $this->total = $summary->total->toDecimal();
    }
}
