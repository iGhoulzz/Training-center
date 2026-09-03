<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Portal\Pages;

use App\Domain\Enrollment\Support\AuthenticatedStudent;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * "My overview" — the portal's landing page (design section 4).
 *
 * THE THINNEST OF THE FOUR PAGES, DELIBERATELY.
 * ----------------------------------------------
 * Shows exactly three facts: name, student code, status. Nothing else — no
 * contact details, no date of birth, no national id, none of the rest of the
 * student record `view_own_student_record` might suggest it grants. The
 * design table names precisely these three fields and this page shows no more.
 *
 * PLAIN PROPERTIES, NOT THE Student MODEL.
 * ------------------------------------------
 * Livewire serialises every public property of a page into that page's own
 * payload. Holding the full `Student` model here would carry every column the
 * query touched — email, phone, national_id, address — into that payload
 * whether or not the view ever prints them, which is a materially bigger
 * surface than "name, code, status" for no benefit. mount() reads the model
 * once and keeps only the three displayed strings.
 *
 * WHO IS VIEWING, RESOLVED THE ONE WAY THE PORTAL RESOLVES IT.
 * -----------------------------------------------------------
 * AuthenticatedStudent::resolve() — never a student_id read off the request —
 * per design section 4.1. PortalScopeArchTest proves every portal page calls
 * it; it is a source scan and cannot prove the query it feeds was
 * constrained, which is why this file has nothing beyond `resolve()` for it
 * to scan.
 */
class Overview extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    /** First in the sidebar and the panel's home lands a signed-in student on WHEN THEY HOLD
     * view_own_student_record. The mechanism is RedirectToHomeController on the
     * panel home, not auth.getRedirectUrl(), and the outcome is conditional: a
     * student holding only view_own_balance lands on /portal/my-balance instead. */
    protected static ?int $navigationSort = -1;

    protected string $view = 'portal.overview';

    public string $studentName = '';

    public string $studentCode = '';

    public string $statusLabel = '';

    /**
     * `$user->can(...)`, never `hasRole()` (CLAUDE.md non-negotiable 1). This
     * is the courtesy gate — keeps the page out of navigation and out of
     * direct reach for an actor without the ability; a crafted request still
     * meets the same check, since canAccess() is consulted on every mount and
     * every subsequent Livewire hydration (Concerns\CanAuthorizeAccess), not
     * only on first render.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->can('view_own_student_record') ?? false;
    }

    public function getTitle(): string
    {
        return __('portal.overview_title');
    }

    public static function getNavigationLabel(): string
    {
        return __('portal.overview_navigation_label');
    }

    public function mount(): void
    {
        $student = app(AuthenticatedStudent::class)->resolve();

        $this->studentName = $student->full_name;
        $this->studentCode = $student->student_code;
        $this->statusLabel = $student->status->label();
    }
}
