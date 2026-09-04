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
 * NO CATALOGUE CONTEXT ON THIS PAGE — A DELIBERATE DECISION THE SPEC DOES
 * NOT COVER.
 * -------------------------------------------------------------------------
 * The design table lists this page's contents as "per-enrolment outstanding
 * and a total", and `StudentBalanceQuery` hands back exactly that shape:
 * `EnrollmentBalance` carries an enrolment id, a nullable charge id and an
 * outstanding `Money` — no course or batch name. Enriching a row with a
 * course or batch name would mean this Finance-domain page either querying
 * `enrollments` directly — forbidden by `docs/ENGINEERING.md`'s "Finance
 * reads enrollment data via EnrollmentQueryService, never by querying
 * enrollments directly" — or re-deriving `EnrollmentQueryService::
 * joinCatalogueTo()`'s join logic by hand, which would duplicate exactly the
 * knowledge that method exists to keep on the Enrollment side of the
 * boundary. Neither is in this task's file scope, and both are more
 * cross-domain reach than "outstanding and a total" asks for. Rows are
 * therefore identified by enrolment id alone; a richer label is future work
 * for whichever task is willing to extend `EnrollmentQueryService` or
 * `StudentBalanceQuery` deliberately.
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
     * @var array<int, array{enrollment_id: int, outstanding: string}>
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
                'outstanding' => $balance->outstanding->toDecimal(),
            ])
            ->values()
            ->all();

        $this->total = $summary->total->toDecimal();
    }
}
