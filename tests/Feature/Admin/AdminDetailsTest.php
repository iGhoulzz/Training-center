<?php

declare(strict_types=1);

use App\Domain\Enrollment\Filament\Resources\BatchResource\Pages\ViewBatch;
use App\Domain\Enrollment\Filament\Resources\CourseResource\Pages\ViewCourse;
use App\Domain\Enrollment\Filament\Resources\StudentCertificateResource\Pages\ViewStudentCertificate;
use App\Domain\Enrollment\Filament\Resources\StudentResource\Pages\ViewStudent;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Enrollment\Models\StudentCertificate;
use App\Domain\Finance\Filament\Resources\ChargeResource\Pages\ViewCharge;
use App\Domain\Finance\Filament\Resources\DiscountResource\Pages\ViewDiscount;
use App\Domain\Finance\Filament\Resources\ExpenseResource\Pages\ViewExpense;
use App\Domain\Finance\Filament\Resources\PaymentResource\Pages\ViewPayment;
use App\Domain\Finance\Filament\Resources\PayrollRunResource\Pages\ReviewPayrollRun;
use App\Domain\Finance\Filament\Resources\StaffCompensationResource\Pages\ViewStaffCompensation;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Discount;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PayrollRun;
use App\Domain\Finance\Models\StaffCompensation;
use App\Domain\Publications\Filament\Resources\ArticleResource\Pages\ViewArticle;
use App\Domain\Publications\Models\Article;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Filament\Resources\ActivityResource\Pages\ViewActivity;
use App\Domain\Staff\Filament\Resources\StaffProfileResource\Pages\ViewStaffProfile;
use App\Domain\Staff\Models\StaffProfile;
use App\Domain\Staff\Support\ActivityEvent;
use App\Filament\Details\AdminDetails;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * Every admin detail page is read-only text in section cards (P4-T42).
 *
 * Each page renders in English and in Arabic with its record's own values, and none of them
 * draws a form input: the Students and Courses pages used to be a form switched to disabled.
 * The expected values are the literals the records are built with, not read back from the page.
 *
 * THE ACTOR HOLDS EXACTLY THE ONE PERMISSION THE PAGE NEEDS (plus the panel), never super admin.
 */
uses(RefreshDatabase::class);

/**
 * An active user holding exactly the named permissions.
 *
 * @param  list<string>  $permissions
 */
function detailsActorHolding(array $permissions): User
{
    $role = Role::query()->create(['name' => 'viewer_'.uniqid(), 'guard_name' => 'web']);
    $writer = app(SystemRoleWriter::class);
    $writer->syncRolePermissions($role, ['access_admin_panel', ...$permissions]);

    $user = User::factory()->create(['is_active' => true]);
    $writer->assignRoles($user, $role->name);

    return $user;
}

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

it('renders every detail page as text in cards, in English and Arabic', function (string $locale, string $pageClass, array $permissions, Closure $record, array $values, string $arabicHeading) {
    app()->setLocale($locale);

    $model = $record();

    $page = Livewire::actingAs(detailsActorHolding($permissions))
        ->test($pageClass, ['record' => $model->getKey()])
        ->assertOk();

    foreach ($values as $value) {
        $page->assertSee($value);
    }

    // The section card the shared component draws, and the page's schema class.
    $page->assertSeeHtml(AdminDetails::CSS_CLASS)->assertSeeHtml('fi-section');

    if ($locale === 'ar') {
        $page->assertSee($arabicHeading);
    }

    // Not a form: no field wrapper and no input is drawn in the body.
    $page->assertDontSeeHtml('fi-fo-field');
})->with(function () {
    $pages = [
        'student' => [ViewStudent::class, ['view_any_student', 'view_student'], fn () => Student::factory()->create(['first_name' => 'Zainab', 'last_name' => 'Quraishi', 'email' => 'zainab.q@example.test', 'gender' => 'female', 'address' => "12 Palm Road\nTripoli"]), ['Zainab', 'Quraishi', 'zainab.q@example.test', '12 Palm Road'], 'الهوية'],
        'course' => [ViewCourse::class, ['view_any_course', 'view_course'], fn () => Course::factory()->create(['code' => 'CRS-DET1', 'name_en' => 'Detail Course', 'description_en' => 'First line']), ['CRS-DET1', 'Detail Course', 'First line'], 'بيانات الدورة'],
        'batch' => [ViewBatch::class, ['view_any_batch', 'view_batch'], fn () => Batch::factory()->create(['code' => 'BAT-DET1']), ['BAT-DET1'], 'بيانات الدفعة'],
        'staff profile' => [ViewStaffProfile::class, ['view_any_staff_profile', 'view_staff_profile'], fn () => StaffProfile::factory()->create(['job_title' => 'Registrar']), ['Registrar'], 'البيانات الوظيفية'],
        'payment' => [ViewPayment::class, ['view_any_payment', 'view_payment'], fn () => Payment::factory()->create(['recorded_by' => User::factory()->create(['name' => 'Recorder Probe'])]), ['Recorder Probe'], 'بيانات الإيصال'],
        'expense' => [ViewExpense::class, ['view_any_expense', 'view_expense'], fn () => Expense::factory()->create(['amount' => '125.500', 'description' => 'Printer paper']), ['125.500', 'Printer paper'], 'بيانات المصروف'],
        'charge' => [ViewCharge::class, ['view_any_charge', 'view_charge'], fn () => Charge::factory()->create(['list_price' => '900.000']), ['900.000'], 'بيانات الفاتورة'],
        'discount' => [ViewDiscount::class, ['view_any_discount', 'view_discount'], fn () => Discount::factory()->create(['name' => 'Early bird', 'percentage' => '10.00']), ['Early bird', '10.00'], 'تعريف الخصم'],
        'article' => [ViewArticle::class, ['view_any_article', 'view_article'], fn () => Article::factory()->create(['title_en' => 'Safety handbook', 'authors' => 'Probe Author Name']), ['Safety handbook', 'Probe Author Name'], 'تفاصيل المقال'],
        'certificate' => [ViewStudentCertificate::class, ['view_any_student_certificate', 'view_student_certificate'], fn () => StudentCertificate::factory()->create(['student_name' => 'Hana Mansour']), ['Hana Mansour'], 'بيانات الشهادة المطبوعة'],
        'pay rate' => [ViewStaffCompensation::class, ['view_any_staff_compensation', 'view_staff_compensation'], fn () => StaffCompensation::factory()->create(['amount' => '3000.000']), ['3000.000'], 'معدل الأجر'],
        'payroll run' => [ReviewPayrollRun::class, ['view_any_payroll_run', 'view_payroll_run'], fn () => PayrollRun::factory()->create(['notes' => 'March run']), ['March run'], 'بيانات المسير'],
        'activity' => [ViewActivity::class, ['view_any_activity', 'view_activity'], fn () => activity()->event(ActivityEvent::PERMISSIONS_CHANGED)->withProperties(['reason' => 'Probe reason text'])->log('Probe entry'), ['Probe reason text'], 'النشاط'],
    ];

    foreach (['en', 'ar'] as $locale) {
        foreach ($pages as $name => [$pageClass, $permissions, $record, $values, $heading]) {
            yield "{$name} ({$locale})" => [$locale, $pageClass, $permissions, $record, $values, $heading];
        }
    }
});

it('shows a missing value as a muted dash and not as blank', function () {
    $student = Student::factory()->create(['national_id' => null, 'notes' => null]);

    $page = Livewire::actingAs(detailsActorHolding(['view_any_student', 'view_student']))
        ->test(ViewStudent::class, ['record' => $student->getKey()])
        ->assertOk();

    // National ID and notes are empty on this record: each shows the shared dash.
    expect(substr_count(html_entity_decode($page->html()), __('details.empty')))->toBeGreaterThanOrEqual(2);
});

it('keeps the staff owner inside the first card, not floating above the cards', function () {
    $profile = StaffProfile::factory()->create();
    $owner = $profile->user->name;

    $html = Livewire::actingAs(detailsActorHolding(['view_any_staff_profile', 'view_staff_profile']))
        ->test(ViewStaffProfile::class, ['record' => $profile->getKey()])
        ->assertOk()
        ->html();

    $firstSection = strpos($html, 'fi-section');
    $ownerAt = strpos($html, $owner);

    // Not vacuous: both are on the page, and the name comes after the first card opens.
    expect($firstSection)->not->toBeFalse()->and($ownerAt)->not->toBeFalse()->and($ownerAt)->toBeGreaterThan($firstSection);
});

it('builds an entry for each shape the pages repeat', function () {
    $entry = AdminDetails::money('amount', 'Amount', 'charges.amount_lyd');

    expect($entry)->toBeInstanceOf(TextEntry::class)
        // Money is the decimal string through the domain's own key, never a float.
        ->and(app()->call(fn () => (string) __('charges.amount_lyd', ['amount' => '450.000'])))->toBe('450.000 LYD')
        ->and(AdminDetails::code('reference', 'Reference')->getName())->toBe('reference');
});

describe('sensitive fields stay behind their permission', function () {
    it('shows the course price only to a viewer who may manage pricing', function () {
        $course = Course::factory()->create(['default_price' => '250.000']);

        // 250.000 LYD, typed by hand.
        Livewire::actingAs(detailsActorHolding(['view_any_course', 'view_course', 'manage_pricing']))
            ->test(ViewCourse::class, ['record' => $course->getKey()])
            ->assertSee('250.000 LYD');

        Livewire::actingAs(detailsActorHolding(['view_any_course', 'view_course']))
            ->test(ViewCourse::class, ['record' => $course->getKey()])
            ->assertOk()
            ->assertDontSee('250.000')
            ->assertDontSee(__('pricing.course_default_price'));
    });

    it('shows the batch price only to a viewer who may manage pricing', function () {
        $batch = Batch::factory()->create(['price' => '175.000']);

        Livewire::actingAs(detailsActorHolding(['view_any_batch', 'view_batch', 'manage_pricing']))
            ->test(ViewBatch::class, ['record' => $batch->getKey()])
            ->assertSee('175.000 LYD');

        Livewire::actingAs(detailsActorHolding(['view_any_batch', 'view_batch']))
            ->test(ViewBatch::class, ['record' => $batch->getKey()])
            ->assertOk()
            ->assertDontSee('175.000')
            ->assertDontSee(__('pricing.batch_price'));
    });
});
