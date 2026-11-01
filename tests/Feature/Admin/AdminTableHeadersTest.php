<?php

declare(strict_types=1);

use App\Domain\Enrollment\Filament\Pages\CertificateIssuanceQueue;
use App\Domain\Enrollment\Filament\Resources\BatchResource\Pages\ListBatches;
use App\Domain\Enrollment\Filament\Resources\BatchResource\Pages\ViewBatch;
use App\Domain\Enrollment\Filament\Resources\BatchResource\RelationManagers\EnrollmentsRelationManager;
use App\Domain\Enrollment\Filament\Resources\BatchResource\RelationManagers\InstructorsRelationManager;
use App\Domain\Enrollment\Filament\Resources\CourseResource\Pages\ListCourses;
use App\Domain\Enrollment\Filament\Resources\StudentCertificateResource\Pages\ListStudentCertificates;
use App\Domain\Enrollment\Filament\Resources\StudentResource\Pages\ListStudents;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Enrollment\Models\StudentCertificate;
use App\Domain\Finance\Exports\RevenueReportExporter;
use App\Domain\Finance\Filament\Pages\ExportHistory;
use App\Domain\Finance\Filament\Resources\ChargeResource\Pages\ListCharges;
use App\Domain\Finance\Filament\Resources\DiscountResource\Pages\ListDiscounts;
use App\Domain\Finance\Filament\Resources\ExpenseCategoryResource\Pages\ListExpenseCategories;
use App\Domain\Finance\Filament\Resources\ExpenseResource\Pages\ListExpenses;
use App\Domain\Finance\Filament\Resources\PaymentResource\Pages\ListPayments;
use App\Domain\Finance\Filament\Resources\PayrollRunResource\Pages\ListPayrollRuns;
use App\Domain\Finance\Filament\Resources\StaffCompensationResource\Pages\ListStaffCompensations;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Discount;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PayrollRun;
use App\Domain\Finance\Models\StaffCompensation;
use App\Domain\Publications\Filament\Resources\ArticleResource\Pages\ListArticles;
use App\Domain\Publications\Models\Article;
use App\Domain\Staff\Filament\Resources\ActivityResource\Pages\ListActivities;
use App\Domain\Staff\Filament\Resources\RoleResource\Pages\ListRoles;
use App\Domain\Staff\Filament\Resources\StaffProfileResource\Pages\ListStaffProfiles;
use App\Domain\Staff\Filament\Resources\StaffProfileResource\Pages\ViewStaffProfile;
use App\Domain\Staff\Filament\Resources\StaffProfileResource\RelationManagers\CertificatesRelationManager;
use App\Domain\Staff\Filament\Resources\UserResource\Pages\ListUsers;
use App\Domain\Staff\Models\StaffCertificate;
use App\Domain\Staff\Models\StaffProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Dom\HTMLDocument;
use Dom\XPath;
use Filament\Actions\Exports\Models\Export;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/**
 * The header row of the page's table, left to right.
 *
 * @return array<int, string>
 */
function adminTableHeaderRow(string $html): array
{
    $xpath = new XPath(HTMLDocument::createFromString($html, LIBXML_NOERROR | \Dom\HTML_NO_DEFAULT_NS));
    $labels = [];

    foreach ($xpath->query('//table[contains(concat(" ", normalize-space(@class), " "), " fi-ta-table ")]/thead/tr/th') as $cell) {
        $labels[] = trim((string) preg_replace('/\s+/', ' ', $cell->textContent));
    }

    return $labels;
}

/** Whether any element carries $token as a whole class, not as the start of a longer one. */
function adminTableHasClassToken(string $html, string $token): bool
{
    $xpath = new XPath(HTMLDocument::createFromString($html, LIBXML_NOERROR | \Dom\HTML_NO_DEFAULT_NS));

    return $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " '.$token.' ")]')->length > 0;
}

/**
 * Whether $expected appears in $labels as a subsequence: every label present, in that order.
 *
 * @param  array<int, string>  $labels
 * @param  array<int, string>  $expected
 */
function headerRowHasInOrder(array $labels, array $expected): bool
{
    $at = -1;

    foreach ($expected as $label) {
        $found = array_search($label, $labels, true);

        if ($found === false || $found <= $at) {
            return false;
        }

        $at = $found;
    }

    return true;
}

/*
 * One row per admin list. `english` is written out by hand, so a header that names the
 * wrong lang key fails here; `keys` are the same headers' keys, read in Arabic to prove
 * the catalogue has a different, non-empty line for each.
 */
dataset('admin lists', [
    'students' => [ListStudents::class, ['view_any_student', 'view_student'], fn () => Student::factory()->create(),
        ['Student', 'Student code', 'Status'], ['enrollment.student', 'enrollment.student_code', 'enrollment.status']],
    'certificates' => [ListStudentCertificates::class, ['view_any_student_certificate', 'view_student_certificate'], fn () => StudentCertificate::factory()->create(),
        ['Student', 'Course', 'Reference', 'Issued on', 'Status'], ['certificates.student', 'certificates.course', 'certificates.reference_number', 'certificates.issued_at', 'certificates.status']],
    'courses' => [ListCourses::class, ['view_any_course'], fn () => Course::factory()->create(),
        ['Course code', 'Course name', 'Total hours', 'Status', 'Batches'], ['enrollment.course_code', 'enrollment.course_name', 'enrollment.total_hours', 'enrollment.status', 'enrollment.batches']],
    'batches' => [ListBatches::class, ['view_any_batch'], fn () => Batch::factory()->create(),
        ['Batch code', 'Status', 'Schedule'], ['enrollment.batch_code', 'enrollment.status', 'enrollment.schedule']],
    'charges' => [ListCharges::class, ['view_any_charge'], fn () => Charge::factory()->create(),
        ['Student', 'Bill reference', 'Billed amount', 'Outstanding', 'Payment state', 'Due date'], ['charges.student', 'charges.reference', 'charges.amount', 'charges.outstanding', 'charges.payment_state', 'charges.due_date']],
    'payments' => [ListPayments::class, ['view_any_payment'], fn () => Payment::factory()->create(),
        ['Receipt reference', 'Student', 'Received', 'Amount received', 'State'], ['payments.reference', 'payments.student', 'payments.received_at', 'payments.total', 'payments.payment_state']],
    'expenses' => [ListExpenses::class, ['view_any_expense'], fn () => Expense::factory()->create(),
        ['Date paid', 'Category', 'Amount (LYD)', 'Status'], ['expenses.paid_on', 'expenses.category', 'expenses.amount', 'expenses.status']],
    'expense categories' => [ListExpenseCategories::class, ['view_any_expense_category'], fn () => ExpenseCategory::factory()->create(),
        ['Name (English)', 'Active'], ['expenses.category_name_en', 'expenses.category_is_active']],
    'discounts' => [ListDiscounts::class, ['view_any_discount'], fn () => Discount::factory()->create(),
        ['Name', 'Percentage', 'Active'], ['pricing.discount_name', 'pricing.discount_percentage', 'pricing.active']],
    'pay rates' => [ListStaffCompensations::class, ['view_any_staff_compensation'], fn () => StaffCompensation::factory()->create(),
        ['Employee', 'Amount', 'Effective from'], ['payroll.employee', 'payroll.amount', 'payroll.effective_from']],
    'payroll runs' => [ListPayrollRuns::class, ['view_any_payroll_run'], fn () => PayrollRun::factory()->create(),
        ['Run type', 'State', 'Frozen line total'], ['payroll.run_type', 'payroll.state', 'payroll.run_total']],
    'activity log' => [ListActivities::class, ['view_any_activity'], function () {
        activity()->event('created')->log('created');

        return Activity::query()->latest('id')->firstOrFail();
    }, ['When', 'Who', 'What happened', 'Record', 'Summary', 'IP address'], ['activity.when', 'activity.who', 'activity.what_happened', 'activity.record', 'activity.summary', 'activity.ip']],
    'users' => [ListUsers::class, ['view_any_user'], fn () => User::factory()->create(),
        ['Name', 'Roles', 'Last login', 'Active'], ['staff.name', 'staff.roles', 'staff.last_login', 'staff.is_active']],
    'staff profiles' => [ListStaffProfiles::class, ['view_any_staff_profile'], fn () => StaffProfile::factory()->create(),
        ['Name', 'Job title', 'Employment type', 'Hire date'], ['staff.name', 'staff.job_title', 'staff.employment_type', 'staff.hire_date']],
    'roles' => [ListRoles::class, ['view_any_role'], fn () => Role::query()->where('name', 'staff')->firstOrFail(),
        ['Roles', 'Authentication guard', 'Role and permissions', 'Last updated'], ['staff.roles', 'staff.guard', 'staff.role_details', 'staff.updated_at']],
    'articles' => [ListArticles::class, ['view_any_article'], fn () => Article::factory()->create(),
        ['Title (English)', 'Topic', 'Issue date'], ['publications.title_en', 'publications.topic', 'publications.issued_on']],
]);

it('draws a header row naming every column, and still lists its records', function (string $page, array $permissions, Closure $make, array $english, array $keys) {
    $actor = User::factory()->create(['is_active' => true]);
    $actor->givePermissionTo(['access_admin_panel', ...$permissions]);
    expect($actor->roles()->exists())->toBeFalse();
    $record = $make();

    $component = Livewire::actingAs($actor)->test($page)->assertCanSeeTableRecords([$record]);
    $labels = adminTableHeaderRow($component->html());

    expect(headerRowHasInOrder($labels, $english))->toBeTrue('Header row: '.json_encode($labels))
        ->and(adminTableHasClassToken($component->html(), 'tc-table'))->toBeTrue('The shared table\'s class is not on the page.')
        ->and(adminTableHasClassToken($component->html(), 'tc-table-row'))->toBeTrue('No body row carries the shared row class.')
        ->and($component->html())->not->toContain('fi-ta-grid')->not->toContain('fi-ta-stack');
})->with('admin lists');

it('draws the same header row in Arabic', function (string $page, array $permissions, Closure $make, array $english, array $keys) {
    $actor = User::factory()->create(['is_active' => true]);
    $actor->givePermissionTo(['access_admin_panel', ...$permissions]);
    $record = $make();
    app()->setLocale('ar');
    $arabic = array_map(fn (string $key): string => __($key), $keys);

    // The catalogue has a line of its own for every header, not the English one.
    expect($arabic)->each->not->toBe('')
        ->and(array_map(fn (string $key): string => __($key, [], 'en'), $keys))->not->toBe($arabic);

    $component = Livewire::actingAs($actor)->test($page)->assertCanSeeTableRecords([$record]);
    $labels = adminTableHeaderRow($component->html());

    expect(headerRowHasInOrder($labels, $arabic))->toBeTrue('Header row: '.json_encode($labels));
})->with('admin lists');

it('labels the row-actions column, in both languages, when a row has actions', function (string $locale, string $expected) {
    $actor = User::factory()->create(['is_active' => true]);
    $actor->givePermissionTo(['access_admin_panel', 'view_any_student', 'view_student']);
    Student::factory()->create();
    app()->setLocale($locale);

    $labels = adminTableHeaderRow(Livewire::actingAs($actor)->test(ListStudents::class)->html());

    expect(end($labels))->toBe($expected);
})->with([['en', 'Actions'], ['ar', 'الإجراءات']]);

it('has a header-order check that can fail', function () {
    expect(headerRowHasInOrder(['A', 'B', 'C'], ['A', 'C']))->toBeTrue()
        ->and(headerRowHasInOrder(['A', 'B', 'C'], ['C', 'A']))->toBeFalse()
        ->and(headerRowHasInOrder(['A', 'B'], ['A', 'Z']))->toBeFalse()
        ->and(adminTableHeaderRow('<div><p>no table here</p></div>'))->toBe([])
        ->and(adminTableHasClassToken('<div class="tc-table-row"></div>', 'tc-table'))->toBeFalse()
        ->and(adminTableHasClassToken('<div class="a tc-table b"></div>', 'tc-table'))->toBeTrue();
});

/*
 * The tables that are not a resource's list: two relation managers on a batch, one on a staff
 * profile, the certificate queue and the export history. Each mounts through `mount`, which takes
 * the actor and returns the component, and has its own English and Arabic header row.
 */
dataset('admin tables beyond the lists', [
    'batch instructors' => [['view_batch'], function (User $actor) {
        $batch = Batch::factory()->active()->create();
        $instructor = User::factory()->create();
        StaffProfile::factory()->for($instructor)->instructor()->create();
        $batch->instructors()->attach($instructor, ['assigned_hours' => 10]);

        return [Livewire::actingAs($actor)->test(InstructorsRelationManager::class, ['ownerRecord' => $batch, 'pageClass' => ViewBatch::class]), $instructor];
    }, ['Instructor', 'Employment', 'Assigned hours'], ['enrollment.instructor', 'enrollment.instructor_employment_state', 'enrollment.assigned_hours']],
    'batch enrolments' => [['view_batch', 'view_any_enrollment'], function (User $actor) {
        $batch = Batch::factory()->active()->create();
        $enrollment = Enrollment::factory()->for($batch)->create();

        return [Livewire::actingAs($actor)->test(EnrollmentsRelationManager::class, ['ownerRecord' => $batch, 'pageClass' => ViewBatch::class]), $enrollment];
    }, ['Student', 'Student code', 'Status', 'Enrolled on'], ['enrollment.student', 'enrollment.student_code', 'enrollment.status', 'enrollment.enrolled_at']],
    'staff certificates' => [['view_staff_profile', 'view_any_staff_certificate'], function (User $actor) {
        $profile = StaffProfile::factory()->create();
        $certificate = StaffCertificate::factory()->for($profile, 'staffProfile')->create();

        return [Livewire::actingAs($actor)->test(CertificatesRelationManager::class, ['ownerRecord' => $profile, 'pageClass' => ViewStaffProfile::class]), $certificate];
    }, ['Title', 'Issued on', 'Expires on', 'Expired'], ['staff.certificate_title', 'staff.issued_on', 'staff.expires_on', 'staff.expired']],
    'certificate queue' => [['issue_student_certificate'], function (User $actor) {
        $enrollment = Enrollment::factory()->completed()->create();

        return [Livewire::actingAs($actor)->test(CertificateIssuanceQueue::class), $enrollment];
    }, ['Student code', 'Student', 'Course', 'Batch', 'Completed on'], ['certificates.queue_student_code', 'certificates.student', 'certificates.course', 'certificates.queue_batch', 'certificates.completed_on']],
    'export history' => [['view_financial_report', 'export_financial_report'], function (User $actor) {
        $export = new Export(['exporter' => RevenueReportExporter::class, 'file_disk' => 'local', 'file_name' => 'report', 'total_rows' => 3, 'completed_at' => null, 'processed_rows' => 0, 'successful_rows' => 0]);
        $export->user()->associate($actor);
        $export->save();

        return [Livewire::actingAs($actor)->test(ExportHistory::class), $export];
    }, ['Report', 'Requested', 'Rows', 'Status'], ['export_history.columns.report', 'export_history.columns.requested_at', 'export_history.columns.rows', 'export_history.columns.status']],
]);

it('draws a header row on every other admin table, in English', function (array $permissions, Closure $mount, array $english, array $keys) {
    $actor = User::factory()->create(['is_active' => true]);
    $actor->givePermissionTo(['access_admin_panel', ...$permissions]);
    [$component, $record] = $mount($actor);

    $labels = adminTableHeaderRow($component->html());

    $component->assertCanSeeTableRecords([$record]);
    expect(headerRowHasInOrder($labels, $english))->toBeTrue('Header row: '.json_encode($labels))
        ->and(adminTableHasClassToken($component->html(), 'tc-table'))->toBeTrue()
        ->and($component->html())->not->toContain('fi-ta-grid')->not->toContain('fi-ta-stack');
})->with('admin tables beyond the lists');

it('draws the same header row on every other admin table, in Arabic', function (array $permissions, Closure $mount, array $english, array $keys) {
    $actor = User::factory()->create(['is_active' => true]);
    $actor->givePermissionTo(['access_admin_panel', ...$permissions]);
    app()->setLocale('ar');
    $arabic = array_map(fn (string $key): string => __($key), $keys);
    expect(array_map(fn (string $key): string => __($key, [], 'en'), $keys))->not->toBe($arabic);

    [$component, $record] = $mount($actor);
    $component->assertCanSeeTableRecords([$record]);
    $labels = adminTableHeaderRow($component->html());

    expect(headerRowHasInOrder($labels, $arabic))->toBeTrue('Header row: '.json_encode($labels));
})->with('admin tables beyond the lists');
