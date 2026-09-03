<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Portal\Pages;

use App\Domain\Enrollment\Enums\CertificateStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\StudentCertificate;
use App\Domain\Enrollment\Support\AuthenticatedStudent;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * "My enrolments" (design section 4).
 *
 * TWO ABILITIES, ONE PAGE.
 * ------------------------
 * `view_own_enrollment` gates the page itself — course, batch, dates, status.
 * `view_own_certificate` is a SEPARATE grant that additionally reveals the
 * certificate reference and status on the same rows, when a `valid`
 * certificate exists. It is not a fifth page: design section 4 is explicit
 * that the certificate fields are "a separate grant on a shared page, not a
 * page of their own." A student holding the first ability but not the second
 * sees every column above the certificate ones, and the certificate columns
 * — header included — are ABSENT from the response, not merely hidden by
 * CSS: showsCertificates() gates both the query in mount() and the `@if` in
 * the view, so the data never leaves the server for an actor without the
 * ability.
 *
 * ONLY A `valid` CERTIFICATE EVER SHOWS A REFERENCE.
 * ---------------------------------------------------
 * mount() filters the bulk certificate lookup to CertificateStatus::Valid.
 * A `replaced` or `revoked` row for the same enrolment is real history, but
 * it is not what design section 4 asks this page to show — "reference and
 * status, when one is valid" — so a student whose only certificate has been
 * superseded or withdrawn sees no reference at all, exactly as a student with
 * no certificate does. There is at most one `valid` row per enrolment
 * (`uniq_valid_certificate_per_enrollment`), so the lookup can never resolve
 * to more than one candidate per key.
 *
 * A FIXED NUMBER OF STATEMENTS, WHATEVER THE ENROLMENT COUNT.
 * -------------------------------------------------------------
 * Design section 4.2's N+1 warning names the balance page, but the plan is
 * explicit that this page carries the identical risk twice over: the
 * certificate lookup AND the course/batch columns are each a per-row
 * temptation. `with('batch.course')` eager-loads both catalogue tables in two
 * more statements, never one per row, and the certificate lookup is a single
 * `whereIn` keyed by every enrolment id at once. PortalQueryCountTest proves
 * one enrolment and twenty cost the same number of queries.
 *
 * PLAIN ARRAYS, NOT Enrollment MODELS.
 * --------------------------------------
 * Livewire serialises every public property into this page's own payload.
 * $rows carries only the seven display fields per row — never a hydrated
 * Enrollment/Batch/Course/StudentCertificate carrying columns this page has
 * no business exposing.
 */
/*
 * A MID-SESSION REVOCATION OF view_own_certificate IS NOT HONOURED UNTIL A FULL
 * PAGE LOAD, AND THAT IS BOUNDED RATHER THAN IGNORED.
 *
 * $showsCertificates is computed in mount(). Livewire's hydration re-runs
 * canAccess(), which checks view_own_enrollment only, so the certificate
 * columns keep rendering from the snapshot for the life of the component.
 *
 * What keeps this a staleness window rather than a leak: certificatesFor()
 * gates the QUERY, not just the view. A student who tampered with the snapshot
 * to force showsCertificates = true would still find no references to render,
 * because none were ever fetched. Gating the query is the security property
 * here; the view flag is presentation.
 */
class MyEnrollments extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?int $navigationSort = 0;

    protected string $view = 'portal.my-enrollments';

    /**
     * One entry per enrolment, newest first.
     *
     * @var array<int, array{
     *     course: string,
     *     batch_code: string,
     *     enrolled_at: string,
     *     completed_at: ?string,
     *     status_label: string,
     *     certificate_reference: ?string,
     *     certificate_status_label: ?string,
     * }>
     */
    public array $rows = [];

    /**
     * Whether this actor may also see the two certificate columns — see the
     * class docblock. Read by the view to decide whether to render the
     * headers at all, not only whether to fill the cells.
     */
    public bool $showsCertificates = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view_own_enrollment') ?? false;
    }

    public function getTitle(): string
    {
        return __('portal.my_enrollments_title');
    }

    public static function getNavigationLabel(): string
    {
        return __('portal.my_enrollments_navigation_label');
    }

    public function mount(): void
    {
        $student = app(AuthenticatedStudent::class)->resolve();

        $this->showsCertificates = auth()->user()?->can('view_own_certificate') ?? false;

        /*
         * ONE STATEMENT FOR THE ENROLMENTS, TWO MORE FOR THE CATALOGUE.
         * Eager loading resolves batch and course in a `WHERE id IN (...)`
         * each, regardless of how many enrolment rows came back — never a
         * query issued once per row.
         */
        $enrollments = Enrollment::query()
            ->where('student_id', $student->getKey())
            ->with('batch.course')
            ->orderByDesc('enrolled_at')
            ->get();

        $certificates = $this->certificatesFor($enrollments);

        $this->rows = $enrollments
            ->map(fn (Enrollment $enrollment): array => $this->rowFor($enrollment, $certificates))
            ->values()
            ->all();
    }

    /**
     * Every VALID certificate for this batch of enrolments, in one statement
     * — never one lookup per row, and never run at all for an actor without
     * view_own_certificate.
     *
     * @param  Collection<int, Enrollment>  $enrollments
     * @return Collection<int, StudentCertificate> keyed by enrollment_id
     */
    private function certificatesFor(Collection $enrollments): Collection
    {
        if (! $this->showsCertificates || $enrollments->isEmpty()) {
            return collect();
        }

        return StudentCertificate::query()
            ->whereIn('enrollment_id', $enrollments->pluck('id'))
            ->where('status', CertificateStatus::Valid)
            ->get()
            ->keyBy('enrollment_id');
    }

    /**
     * @param  Collection<int, StudentCertificate>  $certificates
     * @return array{
     *     course: string,
     *     batch_code: string,
     *     enrolled_at: string,
     *     completed_at: ?string,
     *     status_label: string,
     *     certificate_reference: ?string,
     *     certificate_status_label: ?string,
     * }
     */
    private function rowFor(Enrollment $enrollment, Collection $certificates): array
    {
        /** @var StudentCertificate|null $certificate */
        $certificate = $certificates->get($enrollment->getKey());

        return [
            'course' => $enrollment->batch->course->name(),
            'batch_code' => $enrollment->batch->code,
            'enrolled_at' => $enrollment->enrolled_at->format('Y-m-d'),
            'completed_at' => $enrollment->completed_at?->format('Y-m-d'),
            'status_label' => $enrollment->status->label(),
            'certificate_reference' => $certificate?->reference_number,
            'certificate_status_label' => $certificate?->status->label(),
        ];
    }
}
