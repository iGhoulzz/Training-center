<?php

declare(strict_types=1);

use App\Domain\Enrollment\Enums\BatchStatus;
use App\Domain\Enrollment\Enums\StudentStatus;
use App\Domain\Enrollment\Filament\Pages\CertificateIssuanceQueue;
use App\Domain\Enrollment\Filament\Resources\BatchResource;
use App\Domain\Enrollment\Filament\Resources\BatchResource\Pages\CreateBatch;
use App\Domain\Enrollment\Filament\Resources\BatchResource\Pages\ListBatches;
use App\Domain\Enrollment\Filament\Resources\BatchResource\Pages\ViewBatch;
use App\Domain\Enrollment\Filament\Resources\BatchResource\RelationManagers\EnrollmentsRelationManager;
use App\Domain\Enrollment\Filament\Resources\BatchResource\RelationManagers\InstructorsRelationManager;
use App\Domain\Enrollment\Filament\Resources\CourseResource;
use App\Domain\Enrollment\Filament\Resources\CourseResource\Pages\CreateCourse;
use App\Domain\Enrollment\Filament\Resources\CourseResource\Pages\ListCourses;
use App\Domain\Enrollment\Filament\Resources\StudentCertificateResource;
use App\Domain\Enrollment\Filament\Resources\StudentCertificateResource\Pages\ListStudentCertificates;
use App\Domain\Enrollment\Filament\Resources\StudentResource;
use App\Domain\Enrollment\Filament\Resources\StudentResource\Pages\CreateStudent;
use App\Domain\Enrollment\Filament\Resources\StudentResource\Pages\ListStudents;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Enrollment\Models\StudentCertificate;
use App\Domain\Finance\Filament\Pages\EnrollAndCollect;
use App\Domain\Finance\Filament\Resources\ChargeResource\Pages\ListCharges;
use App\Domain\Finance\Filament\Resources\PaymentResource\Pages\ListPayments;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Staff\Filament\Resources\UserResource;
use App\Domain\Staff\Models\StaffProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Dom\HTMLDocument;
use Dom\XPath;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actor = function (array $permissions): User {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->givePermissionTo(['access_admin_panel', ...$permissions]);
        expect($actor->roles()->exists())->toBeFalse();

        return $actor;
    };
});

it('keeps student row operations compact while preserving delete without edit access', function () {
    $student = Student::factory()->create();
    $actor = ($this->actor)(['view_any_student', 'view_student', 'view_any_charge', 'delete_student', 'issue_portal_credential']);
    $page = Livewire::actingAs($actor)->test(ListStudents::class);
    $document = new DOMDocument;
    @$document->loadHTML($page->html());
    $xpath = new DOMXPath($document);
    $actions = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " fi-ta-record ")]//*[contains(concat(" ", normalize-space(@class), " "), " fi-ta-actions ")]')->item(0);
    expect($actions)->not->toBeNull();
    expect($xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " fi-dropdown-trigger ")]', $actions)->length)->toBe(1);
    expect($xpath->query('.//button[@aria-label="'.__('enrollment.select_student').'"]', $actions)->length)->toBe(1);
    $grid = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " fi-ta-grid ")]')->item(0);
    expect($grid)->not->toBeNull();
    expect($xpath->query('./*', $grid)->length)->toBe(3);
    expect($xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " whitespace-nowrap ")]', $grid)->length)->toBe(1);
    $page->assertTableActionVisible('delete', $student)->callTableAction('delete', $student);
    expect($student->fresh()->trashed())->toBeTrue();
});

it('renders certificate snapshots in explicit responsive columns with an unbroken issue date', function () {
    $certificate = StudentCertificate::factory()->create(['completed_on' => '2025-01-12', 'issued_at' => '2025-03-05 12:00:00']);
    $actor = ($this->actor)(['view_any_student_certificate']);
    $page = Livewire::actingAs($actor)->test(ListStudentCertificates::class);
    $document = new DOMDocument;
    @$document->loadHTML($page->html());
    $xpath = new DOMXPath($document);
    $grid = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " fi-ta-grid ")]')->item(0);
    expect($grid)->not->toBeNull();
    expect($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " fi-ta-record-content ")]/*')->length)->toBe(1);
    expect($xpath->query('./*', $grid)->length)->toBe(4);
    expect($xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " whitespace-nowrap ")]', $grid)->length)->toBeGreaterThanOrEqual(2);
    $page->assertSee($certificate->reference_number)->assertSee('Mar 5, 2025')->assertDontSee('Jan 12, 2025');
});

it('keeps the optional completion date available and sorts it when enabled', function () {
    $early = StudentCertificate::factory()->create(['completed_on' => '2025-01-12']);
    $late = StudentCertificate::factory()->create(['completed_on' => '2025-02-15']);
    $actor = ($this->actor)(['view_any_student_certificate']);
    $page = Livewire::actingAs($actor)->test(ListStudentCertificates::class);
    expect(strip_tags($page->html()))->not->toContain('Jan 12, 2025', 'Feb 15, 2025');
    $page->assertTableColumnExists('completed_on')
        ->toggleAllTableColumns()
        ->sortTable('completed_on', 'asc')->assertCanSeeTableRecords([$early, $late], inOrder: true)
        ->sortTable('completed_on', 'desc')->assertCanSeeTableRecords([$late, $early], inOrder: true);
    expect(strip_tags($page->html()))->toContain('Jan 12, 2025', 'Feb 15, 2025');
    $page = Livewire::actingAs($actor)->test(ListStudentCertificates::class);
    expect(strip_tags($page->html()))->toContain('Jan 12, 2025', 'Feb 15, 2025');
    $page->toggleAllTableColumns(false);
    expect(strip_tags($page->html()))->not->toContain('Jan 12, 2025', 'Feb 15, 2025');
    $page = Livewire::actingAs($actor)->test(ListStudentCertificates::class);
    expect(strip_tags($page->html()))->not->toContain('Jan 12, 2025', 'Feb 15, 2025');
});

it('contains long certificate text on the rendered text items with and without links', function (bool $canFollowLinks) {
    $certificate = StudentCertificate::factory()->create([
        'student_name' => str_repeat('LongStudentName', 12),
        'course_name' => str_repeat('LongCourseName', 12),
    ]);
    $actor = ($this->actor)(['view_any_student_certificate', ...($canFollowLinks
        ? ['view_student_certificate', 'view_any_student', 'view_student', 'view_any_course', 'view_course'] : [])]);
    $page = Livewire::actingAs($actor)->test(ListStudentCertificates::class);
    $document = HTMLDocument::createFromString($page->html(), LIBXML_NOERROR | \Dom\HTML_NO_DEFAULT_NS);
    $xpath = new XPath($document);
    $items = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " fi-ta-grid ")]//*[contains(concat(" ", normalize-space(@class), " "), " fi-ta-text-item ") and contains(concat(" ", normalize-space(@class), " "), " truncate ")]');
    expect($items->length)->toBe(4);
    foreach ($items as $item) {
        expect(explode(' ', $item->getAttribute('class')))->toContain('block');
    }
    $page->assertSee($certificate->student_name)->assertSee($certificate->course_name);
})->with([true, false]);

it('reserves the same certificate action track for rows with and without lifecycle actions', function (string $grant) {
    $valid = StudentCertificate::factory()->create();
    $revoked = StudentCertificate::factory()->revoked()->create();
    $permissions = match ($grant) {
        'reader' => ['view_student_certificate'],
        'operator' => ['replace_student_certificate', 'revoke_student_certificate'],
        'all' => ['view_student_certificate', 'replace_student_certificate', 'revoke_student_certificate'],
        default => [],
    };
    $actor = ($this->actor)(['view_any_student_certificate', ...$permissions]);
    $page = Livewire::actingAs($actor)->test(ListStudentCertificates::class);
    $document = HTMLDocument::createFromString($page->html(), LIBXML_NOERROR | \Dom\HTML_NO_DEFAULT_NS);
    $xpath = new XPath($document);
    $rows = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " fi-ta-record ")]');
    expect($rows->length)->toBe(2);
    foreach ($rows as $row) {
        expect($row->getAttribute('class'))->toContain('md:[&_.fi-ta-record-content-ctn]:grid', 'md:[&_.fi-ta-record-content-ctn]:grid-cols-[minmax(0,1fr)_4.5rem]');
        $track = $xpath->query('./div[contains(concat(" ", normalize-space(@class), " "), " fi-ta-record-content-ctn ")]', $row)->item(0);
        expect($track)->not->toBeNull();
        expect($xpath->query('./div[1]//*[contains(concat(" ", normalize-space(@class), " "), " fi-ta-grid ")]', $track)->length)->toBe(1);
    }
    if (in_array($grant, ['reader', 'all'], true)) {
        foreach ([$valid, $revoked] as $certificate) {
            $page->assertTableActionVisible('view', $certificate);
            expect($xpath->query('//a[@href="'.StudentCertificateResource::getUrl('view', ['record' => $certificate]).'" and contains(@class, "fi-dropdown-list-item") ]')->length)->toBe(1);
        }
        expect($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " fi-ta-record ")]//*[contains(concat(" ", normalize-space(@class), " "), " fi-dropdown-trigger ")]')->length)->toBe(2);
        $this->actingAs($actor)->get(StudentCertificateResource::getUrl('view', ['record' => $valid]))->assertSuccessful();
    } else {
        $page->assertTableActionHidden('view', $valid)->assertTableActionHidden('view', $revoked)
            ->mountTableAction('view', $valid)->assertSet('mountedActions', []);
        $this->actingAs($actor)->get(StudentCertificateResource::getUrl('view', ['record' => $valid]))->assertForbidden();
        expect($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " fi-ta-record ")]//*[contains(concat(" ", normalize-space(@class), " "), " fi-dropdown-trigger ")]')->length)->toBe($grant === 'operator' ? 1 : 0);
    }
    $page->assertTableActionHidden('replace', $revoked)->assertTableActionHidden('revoke', $revoked);
})->with(['reader', 'operator', 'all', 'register only']);

it('keeps certificate operations in a compact authorized row menu', function () {
    $certificate = StudentCertificate::factory()->create();
    $actor = ($this->actor)(['view_any_student_certificate', 'replace_student_certificate', 'revoke_student_certificate']);
    $page = Livewire::actingAs($actor)->test(ListStudentCertificates::class);
    $document = new DOMDocument;
    @$document->loadHTML($page->html());
    $xpath = new DOMXPath($document);
    expect($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " fi-ta-record ")]//*[contains(concat(" ", normalize-space(@class), " "), " fi-dropdown-trigger ")]')->length)->toBe(1);
    $page->assertTableActionVisible('replace', $certificate)->assertTableActionVisible('revoke', $certificate);
    $reader = ($this->actor)(['view_any_student_certificate']);
    Livewire::actingAs($reader)->test(ListStudentCertificates::class)
        ->assertTableActionHidden('replace', $certificate)->assertTableActionHidden('revoke', $certificate);
});

it('refuses crafted certificate lifecycle action mounts without its grant', function (string $action) {
    $certificate = StudentCertificate::factory()->create();
    $actor = ($this->actor)(['view_any_student_certificate', $action.'_student_certificate']);
    Livewire::actingAs($actor)->test(ListStudentCertificates::class)->mountTableAction($action, $certificate)
        ->assertSet('mountedActions.0.name', $action)
        ->assertSet('mountedActions.0.context.recordKey', (string) $certificate->getKey());
    $reader = ($this->actor)(['view_any_student_certificate']);
    Livewire::actingAs($reader)->test(ListStudentCertificates::class)
        ->mountTableAction($action, $certificate)->assertSet('mountedActions', []);
})->with(['replace', 'revoke']);

it('keeps certificate destination links valid after HTML5 parsing for each read grant', function (?string $missingPermission) {
    $certificate = StudentCertificate::factory()->create();
    $permissions = ['view_any_student_certificate', 'view_student_certificate', 'view_any_student', 'view_student', 'view_any_course', 'view_course'];
    $actor = ($this->actor)(array_values(array_diff($permissions, [$missingPermission])));
    $page = Livewire::actingAs($actor)->test(ListStudentCertificates::class);
    $html = $page->html();
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR | \Dom\HTML_NO_DEFAULT_NS);
    $xpath = new XPath($document);
    $grid = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " fi-ta-grid ")]')->item(0);
    expect($grid)->not->toBeNull();
    expect($grid->firstElementChild->textContent)->toContain($certificate->student_name);
    expect($grid->lastElementChild->textContent)->toContain($certificate->status->label());
    expect($xpath->query('.//a[contains(@class, "fi-ta-record-content")]', $grid)->length)->toBe(0);
    expect($html)->not->toMatch('/<a\b[^>]*class="fi-ta-record-content"[^>]*>.*?<a\b/s');
    $destinations = [
        'view_student_certificate' => [StudentCertificateResource::getUrl('view', ['record' => $certificate]), $certificate->reference_number],
        'view_student' => [StudentResource::getUrl('view', ['record' => $certificate->enrollment->student]), $certificate->student_name],
        'view_course' => [CourseResource::getUrl('view', ['record' => $certificate->enrollment->batch->course]), $certificate->course_name],
    ];
    foreach ($destinations as $permission => [$url, $label]) {
        $links = $xpath->query('//a[@href="'.$url.'"]');
        if ($missingPermission === $permission) {
            expect($links->length)->toBe(0);
        } else {
            expect($links->length)->toBe($permission === 'view_student_certificate' ? 2 : 1);
            expect($links->item(0)->textContent)->toContain($label);
        }
    }
})->with([null, 'view_student_certificate', 'view_student', 'view_course']);

it('renders student filters as wrapping pills while retaining scoped counts and filtering', function (bool $canReadCharges) {
    $active = Student::factory()->create(['status' => StudentStatus::Active]);
    $prospective = Student::factory()->create(['status' => StudentStatus::Prospective]);
    $actor = ($this->actor)(['view_any_student', ...($canReadCharges ? ['view_any_charge'] : [])]);
    $page = Livewire::actingAs($actor)->test(ListStudents::class);
    $document = HTMLDocument::createFromString($page->html(), LIBXML_NOERROR | \Dom\HTML_NO_DEFAULT_NS);
    $xpath = new XPath($document);
    $nav = $xpath->query('//nav[@role="tablist"]')->item(0);
    expect($nav)->not->toBeNull();
    expect($nav->parentElement->getAttribute('class'))->toContain('[&_.fi-tabs]:flex-wrap', '[&_.fi-tabs]:gap-2');
    expect(preg_match('/\[&_\.fi-badge\]:opacity-(\d+)/', $nav->parentElement->getAttribute('class'), $opacityMatch))->toBe(1);
    $opacity = (int) $opacityMatch[1] / 100;
    $luminance = function (array $color): float {
        $linear = array_map(fn (float $channel): float => $channel / 255 <= 0.04045
            ? $channel / 255 / 12.92 : (($channel / 255 + 0.055) / 1.055) ** 2.4, $color);

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    };
    foreach ([[[255, 255, 255], 0.04, [156, 163, 175]], [[245, 158, 11], 0.14, [251, 191, 36]]] as [$fill, $fillOpacity, $text]) {
        $surface = array_map(fn (int $channel, int $base): float => $channel * $fillOpacity + $base * (1 - $fillOpacity), $fill, [3, 7, 18]);
        $badge = array_map(fn (int $channel, float $base): float => $channel * $opacity + $base * (1 - $opacity), $text, $surface);
        expect(($luminance($badge) + 0.05) / ($luminance($surface) + 0.05))->toBeGreaterThanOrEqual(4.5);
    }
    $noPortal = $xpath->query('./button[contains(@*[name()="wire:click"], "no_portal")]', $nav)->item(0);
    expect($noPortal->getAttribute('class'))->toContain('before:bg-no-portal');
    $all = $xpath->query('./button[@aria-selected="true"]', $nav)->item(0);
    expect($all->getAttribute('class'))->toContain(
        'rounded-full',
        'before:size-[6px]',
        'border-primary-500/40',
        'bg-primary-500/14',
        'focus-visible:ring-2',
        'focus-visible:ring-primary-400',
    );
    expect($xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " fi-badge-label ")]', $all)->item(0)->textContent)->toContain('2');
    expect($xpath->query('./button[contains(@*[name()="wire:click"], "owes_money")]', $nav)->length)->toBe($canReadCharges ? 1 : 0);
    $page->set('activeTab', 'active')->assertCanSeeTableRecords([$active])->assertCanNotSeeTableRecords([$prospective]);
    $document = HTMLDocument::createFromString($page->html(), LIBXML_NOERROR | \Dom\HTML_NO_DEFAULT_NS);
    $xpath = new XPath($document);
    $selected = $xpath->query('//button[@role="tab" and @aria-selected="true"]')->item(0);
    expect($selected->textContent)->toContain(StudentStatus::Active->label());
    expect($selected->getAttribute('class'))->toContain('bg-primary-500/14', 'before:bg-success-400');
    $page->set('activeTab', 'prospective')->assertCanSeeTableRecords([$prospective])->assertCanNotSeeTableRecords([$active]);
})->with([true, false]);

it('renders batch filters as wrapping status pills with counts and selected styling', function () {
    $course = Course::factory()->create(['total_hours' => 30]);
    $active = Batch::factory()->for($course)->active()->create(['capacity' => 1]);
    Enrollment::factory()->count(2)->for($active)->create();
    $planned = Batch::factory()->for($course)->create(['total_hours' => 0]);
    $completed = Batch::factory()->for($course)->completed()->create(['total_hours' => 0]);
    $actor = ($this->actor)(['view_any_batch']);
    $page = Livewire::actingAs($actor)->test(ListBatches::class);
    $tabs = [
        'all' => [__('enrollment.all'), 'before:bg-gray-400', '3', [$active, $planned, $completed]],
        'active' => [BatchStatus::Active->label(), 'before:bg-success-400', '1', [$active]],
        'planned' => [BatchStatus::Planned->label(), 'before:bg-info-400', '1', [$planned]],
        'over_capacity' => [__('enrollment.over_capacity'), 'before:bg-warning-400', '1', [$active]],
        'hour_mismatch' => [__('enrollment.hour_mismatch'), 'before:bg-danger-400', '1', [$active]],
    ];

    foreach ($tabs as $selectedKey => [, , , $records]) {
        $page->set('activeTab', $selectedKey)->assertCanSeeTableRecords($records)->assertCountTableRecords(count($records));
        $document = HTMLDocument::createFromString($page->html(), LIBXML_NOERROR | \Dom\HTML_NO_DEFAULT_NS);
        $xpath = new XPath($document);
        $nav = $xpath->query('//nav[@role="tablist"]')->item(0);
        expect($nav)->not->toBeNull();
        expect($nav->parentElement->getAttribute('class'))->toContain(
            '[&_.fi-tabs]:flex-wrap', '[&_.fi-tabs]:gap-2', '[&_.fi-tabs]:overflow-visible',
            '[&_.fi-badge]:bg-transparent', '[&_.fi-badge]:text-inherit', '[&_.fi-badge]:opacity-74',
        );
        $buttons = $xpath->query('./button[@role="tab"]', $nav);
        expect($buttons->length)->toBe(5);
        foreach (array_keys($tabs) as $index => $key) {
            [$label, $dot, $count] = $tabs[$key];
            $button = $buttons->item($index);
            expect($button->textContent)->toContain($label);
            expect($button->getAttribute('wire:click'))->toContain($key);
            expect($button->getAttribute('aria-selected'))->toBe($key === $selectedKey ? 'true' : 'false');
            expect($button->getAttribute('class'))->toContain(
                'rounded-full', 'before:size-[6px]', $dot, 'focus-visible:ring-2', 'focus-visible:ring-primary-400',
                ...($key === $selectedKey
                    ? ['border-primary-500/40', 'bg-primary-500/14', 'text-primary-400']
                    : ['border-white/9', 'bg-white/4', 'text-gray-400']),
            );
            $badge = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " fi-badge-label ")]', $button)->item(0);
            expect(trim($badge->textContent))->toBe($count);
        }
    }
});

it('stacks every new student operation inside the preview footer', function () {
    $actor = ($this->actor)(['view_any_student', 'create_student', 'view_student', 'create_enrollment']);
    $page = Livewire::actingAs($actor)->test(CreateStudent::class);
    $document = new DOMDocument;
    @$document->loadHTML($page->html());
    $xpath = new DOMXPath($document);
    $footer = $xpath->query('//aside/*[last()]')->item(0);
    expect($footer)->not->toBeNull();
    expect($xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " fi-ac ")]/button', $footer)->length)->toBe(4);
    expect($footer->getAttribute('class'))->toContain('[&_.fi-ac]:grid-cols-1', '[&_.fi-ac]:w-full');
});

it('keeps over-capacity enrollment available and flagged with the exact grant', function () {
    $batch = Batch::factory()->active()->create(['capacity' => 1]);
    Enrollment::factory()->for($batch)->create();
    $student = Student::factory()->create();
    $permissions = ['view_batch', 'view_any_batch', 'view_any_enrollment'];
    $actor = ($this->actor)([...$permissions, 'create_enrollment']);
    Livewire::actingAs($actor)->test(EnrollmentsRelationManager::class, [
        'ownerRecord' => $batch, 'pageClass' => ViewBatch::class,
    ])->mountTableAction('enroll')->assertSet('mountedActions.0.name', 'enroll');
    Livewire::actingAs($actor)->test(EnrollmentsRelationManager::class, [
        'ownerRecord' => $batch, 'pageClass' => ViewBatch::class,
    ])->callTableAction('enroll', null, ['student_id' => $student->getKey()])
        ->assertNotified(__('enrollment.over_capacity_warning'));
    expect($batch->enrollments()->count())->toBe(2)->and(Charge::query()->count())->toBe(1);
    Livewire::actingAs($actor)->test(ListBatches::class)
        ->assertTableColumnStateSet('enrolment_load', '2 / 1', $batch);
    $denied = ($this->actor)($permissions);
    Livewire::actingAs($denied)->test(EnrollmentsRelationManager::class, [
        'ownerRecord' => $batch, 'pageClass' => ViewBatch::class,
    ])->assertTableActionHidden('enroll')
        ->mountTableAction('enroll')->assertSet('mountedActions', []);
    expect($batch->enrollments()->count())->toBe(2);
});

it('shows debt on the restyled issue form without blocking the exact issuer', function () {
    $enrollment = Enrollment::factory()->completed()->create();
    Charge::factory()->for($enrollment)->create(['list_price' => '750.125', 'amount' => '750.125']);
    $actor = ($this->actor)(['view_any_student_certificate', 'issue_student_certificate']);
    $component = Livewire::actingAs($actor)->test(ListStudentCertificates::class)
        ->mountAction('issue')->assertSet('mountedActions.0.name', 'issue')->setActionData(['enrollment_id' => $enrollment->getKey()]);
    $page = $component->instance();
    $entry = $page->getSchema($page->getMountedActionSchemaName())->getComponent('outstanding_balance');
    expect($entry)->not->toBeNull()->and((string) $entry->getState())->toContain('750.125');
    $component->callMountedAction()->assertHasNoActionErrors();
    expect(StudentCertificate::query()->where('enrollment_id', $enrollment->getKey())->count())->toBe(1);
    $denied = ($this->actor)(['view_any_student_certificate']);
    Livewire::actingAs($denied)->test(ListStudentCertificates::class)
        ->assertActionHidden('issue')->mountAction('issue')->assertSet('mountedActions', []);
    expect(StudentCertificate::query()->count())->toBe(1);
});

it('cannot issue a portal login without email on the restyled student register', function () {
    $student = Student::factory()->create(['email' => null]);
    $permissions = ['view_any_student', 'view_student'];
    $actor = ($this->actor)([...$permissions, 'issue_portal_credential']);
    $usersBefore = User::query()->count();
    Livewire::actingAs($actor)->test(ListStudents::class)
        ->assertTableActionDisabled('issuePortalCredential', $student)
        ->mountTableAction('issuePortalCredential', $student)->assertSet('mountedActions', []);
    expect($student->fresh()->user_id)->toBeNull()->and(User::query()->count())->toBe($usersBefore);
    $withEmail = Student::factory()->create();
    Livewire::actingAs($actor)->test(ListStudents::class)->mountTableAction('issuePortalCredential', $withEmail)
        ->assertSet('mountedActions.0.name', 'issuePortalCredential')
        ->assertSet('mountedActions.0.context.recordKey', (string) $withEmail->getKey())->callMountedAction();
    expect($withEmail->fresh()->user_id)->not->toBeNull();
    $denied = ($this->actor)($permissions);
    $another = Student::factory()->create();
    Livewire::actingAs($denied)->test(ListStudents::class)
        ->assertTableActionHidden('issuePortalCredential', $another)
        ->mountTableAction('issuePortalCredential', $another)->assertSet('mountedActions', []);
    expect($another->fresh()->user_id)->toBeNull();
});

it('groups student creation by identity contact and records', function () {
    $actor = ($this->actor)(['view_any_student', 'create_student']);
    Livewire::actingAs($actor)->test(CreateStudent::class)
        ->assertSee('Identity')->assertSee('Contact')->assertSee('Records')
        ->assertSee('No email means no portal login.');
});

it('opens a selected student in the detail panel and preserves the URL selection', function () {
    $student = Student::factory()->create(['email' => 'detail@example.test']);
    $actor = ($this->actor)(['view_any_student', 'view_student']);
    Livewire::actingAs($actor)->test(ListStudents::class)
        ->callTableAction('selectStudent', $student)
        ->assertSet('selectedStudentId', $student->getKey())->assertSee('detail@example.test');
});

it('renders real course card counts and the eligible certificate queue callout', function () {
    $course = Course::factory()->create();
    Batch::factory()->count(2)->for($course)->create();
    Enrollment::factory()->completed()->create();
    $actor = ($this->actor)(['view_any_course', 'view_any_student_certificate', 'issue_student_certificate']);
    Livewire::actingAs($actor)->test(ListCourses::class)->assertTableColumnStateSet('batches_count', 2, $course);
    Livewire::actingAs($actor)->test(ListStudentCertificates::class)
        ->assertSee('1 completed enrolment awaiting certificate issuance')->assertSee('Review queue');
    Livewire::actingAs($actor)->test(CertificateIssuanceQueue::class)->assertSuccessful();
});

it('applies capacity and hour mismatch tabs to the real batch query', function () {
    $course = Course::factory()->create(['total_hours' => 30]);
    $over = Batch::factory()->for($course)->active()->create(['capacity' => 1]);
    Enrollment::factory()->count(2)->for($over)->create();
    $unlimited = Batch::factory()->for($course)->create(['capacity' => 0, 'total_hours' => 0]);
    Enrollment::factory()->count(2)->for($unlimited)->create();
    $actor = ($this->actor)(['view_any_batch']);
    Livewire::actingAs($actor)->test(ListBatches::class)
        ->set('activeTab', 'over_capacity')->assertCanSeeTableRecords([$over])
        ->assertCanNotSeeTableRecords([$unlimited]);
    Livewire::actingAs($actor)->test(ListBatches::class)
        ->set('activeTab', 'hour_mismatch')->assertCanSeeTableRecords([$over])
        ->assertCanNotSeeTableRecords([$unlimited]);
});

it('applies student tabs without exposing financial data to a student reader', function () {
    $owing = Student::factory()->create();
    $paid = Student::factory()->create();
    $enrollment = Enrollment::factory()->for($owing)->create();
    Charge::factory()->for($enrollment)->create(['list_price' => '456.789', 'amount' => '456.789']);
    $actor = ($this->actor)(['view_any_student', 'view_student', 'view_any_charge']);
    Livewire::actingAs($actor)->test(ListStudents::class)
        ->set('activeTab', 'owes_money')->assertCanSeeTableRecords([$owing])
        ->assertCanNotSeeTableRecords([$paid]);
    $denied = ($this->actor)(['view_any_student', 'view_student']);
    Livewire::actingAs($denied)->test(ListStudents::class)
        ->set('selectedStudentId', $owing->getKey())->assertDontSee('456.789')->assertDontSee('Owes money');
});

it('does not disclose the selected record without its view permission', function () {
    $student = Student::factory()->create(['email' => 'private-detail@example.test']);
    $actor = ($this->actor)(['view_any_student']);
    Livewire::actingAs($actor)->test(ListStudents::class)
        ->assertTableActionHidden('selectStudent', $student)
        ->set('selectedStudentId', $student->getKey())->assertDontSee('private-detail@example.test');
});

it('issues portal credentials from the selected student footer with the same email guard', function () {
    $student = Student::factory()->create();
    $actor = ($this->actor)(['view_any_student', 'view_student', 'issue_portal_credential']);
    $component = Livewire::actingAs($actor)->test(ListStudents::class)
        ->set('selectedStudentId', $student->getKey());
    $component->callAction(TestAction::make('issuePortalCredential')->schemaComponent('studentDetail.actions', 'content'));
    expect($student->fresh()->user_id)->not->toBeNull();
    $noEmail = Student::factory()->create(['email' => null]);
    Livewire::actingAs($actor)->test(ListStudents::class)
        ->set('selectedStudentId', $noEmail->getKey())->assertActionDisabled(TestAction::make('issuePortalCredential')->schemaComponent('studentDetail.actions', 'content'))
        ->mountAction(TestAction::make('issuePortalCredential')->schemaComponent('studentDetail.actions', 'content'))
        ->assertSet('mountedActions', []);
    expect($noEmail->fresh()->user_id)->toBeNull();
});

it('hides the queue from a register reader without the issue permission', function () {
    Enrollment::factory()->completed()->create();
    $actor = ($this->actor)(['view_any_student_certificate']);
    Livewire::actingAs($actor)->test(ListStudentCertificates::class)->assertDontSee('Review queue');
    $this->actingAs($actor)->get(CertificateIssuanceQueue::getUrl())->assertForbidden();
});

it('keeps the issuer name static even when the actor can edit staff accounts', function (string $missingPermission) {
    $issuer = User::factory()->create();
    $certificate = StudentCertificate::factory()->create(['issued_by' => $issuer->getKey()]);
    $permissions = ['view_any_student_certificate', 'view_any_user', 'update_user'];
    $actor = ($this->actor)($permissions);
    $url = UserResource::getUrl('edit', ['record' => $issuer]);
    Livewire::actingAs($actor)->test(ListStudentCertificates::class)
        ->assertTableColumnStateSet('issuedBy.name', $issuer->name, $certificate)
        ->assertSee($issuer->name)
        ->assertDontSee($url, false);
    $denied = ($this->actor)(array_values(array_diff($permissions, [$missingPermission])));
    Livewire::actingAs($denied)->test(ListStudentCertificates::class)
        ->assertTableColumnStateSet('issuedBy.name', $issuer->name, $certificate)
        ->assertSee($issuer->name)
        ->assertDontSee($url, false);
})->with(['update_user', 'view_any_user']);

it('keeps certificate snapshots readable without linking to deleted records', function () {
    $certificate = StudentCertificate::factory()->create();
    $student = $certificate->enrollment->student;
    $studentUrl = StudentResource::getUrl('view', ['record' => $student]);
    $student->delete();
    $certificate->issuedBy->delete();
    $actor = ($this->actor)(['view_any_student_certificate', 'view_any_student', 'view_student', 'view_any_user', 'update_user']);
    Livewire::actingAs($actor)->test(ListStudentCertificates::class)
        ->assertSee($certificate->student_name)->assertDontSee($studentUrl, false)->assertSuccessful();
});

it('consumes authorized enrollment hints without any write on mount', function () {
    $student = Student::factory()->create();
    $batch = Batch::factory()->active()->create();
    $actor = ($this->actor)(['create_enrollment', 'view_student', 'view_batch']);
    Livewire::actingAs($actor)->withQueryParams(['student' => $student->getKey(), 'batch' => $batch->getKey()])
        ->test(EnrollAndCollect::class)->assertSet('data.student_id', $student->getKey())
        ->assertSet('data.batch_id', $batch->getKey());
    expect(Enrollment::query()->count())->toBe(0)->and(Charge::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0);
});

it('keeps global tab counts honest on empty searched student lists', function () {
    Student::factory()->create(['status' => StudentStatus::Active]);
    Student::factory()->create(['status' => StudentStatus::Prospective]);
    $actor = ($this->actor)(['view_any_student']);
    $page = Livewire::actingAs($actor)->test(ListStudents::class)->searchTable('nonexistent-person')->assertCountTableRecords(0);
    $tabs = $page->instance()->getTabs();
    expect((int) $tabs['all']->getBadge())->toBe(2)->and((int) $tabs['active']->getBadge())->toBe(1)
        ->and((int) $tabs['prospective']->getBadge())->toBe(1)->and((int) $tabs['no_portal']->getBadge())->toBe(2);
});

it('renders course money from real finance rows behind independent permissions', function () {
    $course = Course::factory()->create(['default_price' => '990.125']);
    $batch = Batch::factory()->for($course)->create();
    $enrollment = Enrollment::factory()->for($batch)->create();
    $bill = Charge::factory()->for($enrollment)->create(['list_price' => '900.333', 'amount' => '900.333']);
    $payment = Payment::factory()->create(['received_at' => now()]);
    PaymentAllocation::factory()->for($payment)->for($bill)->create(['amount' => '123.456']);
    $reversed = Payment::factory()->reversed()->create(['received_at' => now()]);
    PaymentAllocation::factory()->for($reversed)->for($bill)->create(['amount' => '321.765']);
    $reader = ($this->actor)(['view_any_course']);
    Livewire::actingAs($reader)->test(ListCourses::class)->assertDontSee('990.125')->assertDontSee('123.456')
        ->assertDontSee(__('enrollment.enrolled_students', ['count' => 1]));
    $reporter = ($this->actor)(['view_any_course', 'view_financial_report']);
    Livewire::actingAs($reporter)->test(ListCourses::class)->assertSee('123.456')->assertDontSee('321.765')->assertDontSee('990.125');
    $pricing = ($this->actor)(['view_any_course', 'manage_pricing']);
    Livewire::actingAs($pricing)->test(ListCourses::class)->assertSee('990.125')->assertDontSee('123.456');
});

it('creates a student before chaining to the real enrollment receiver and updates its live summary', function () {
    $actor = ($this->actor)(['view_any_student', 'create_student', 'view_student', 'create_enrollment']);
    $page = Livewire::actingAs($actor)->test(CreateStudent::class)
        ->fillForm(['first_name' => 'LiveSummaryFirst', 'last_name' => 'LiveSummaryLast', 'email' => 'summary@example.test'])
        ->assertSee('LiveSummaryFirst LiveSummaryLast')->assertSee(__('enrollment.email_allows_portal'))
        ->callAction(TestAction::make('createAndEnroll')->schemaComponent('preview.form-actions', 'form'));
    $student = Student::query()->where('email', 'summary@example.test')->sole();
    $page->assertRedirect(EnrollAndCollect::getUrl(['student' => $student->getKey()]));
    expect(Enrollment::query()->count())->toBe(0)->and(Charge::query()->count())->toBe(0);
    $denied = ($this->actor)(['view_any_student', 'create_student', 'view_student']);
    Livewire::actingAs($denied)->test(CreateStudent::class)
        ->assertDontSee(__('enrollment.create_and_enroll'));
});

it('creates a course then opens a batch form with the newly created course', function () {
    $actor = ($this->actor)(['view_any_course', 'create_course', 'view_course', 'view_any_batch', 'create_batch']);
    $page = Livewire::actingAs($actor)->test(CreateCourse::class)->fillForm(['code' => 'CHAIN-C', 'name_en' => 'Chain course', 'total_hours' => 36])
        ->callAction(TestAction::make('createAndBatch')->schemaComponent('form-actions', 'form'));
    $course = Course::query()->where('code', 'CHAIN-C')->sole();
    $page->assertRedirect(BatchResource::getUrl('create', ['course' => $course->getKey()]));
    Livewire::actingAs($actor)->withQueryParams(['course' => $course->getKey()])->test(CreateBatch::class)
        ->assertSet('data.course_id', $course->getKey())->assertSee('Chain course');
    expect(Batch::query()->count())->toBe(0);
});

it('creates a batch before chaining and preserves inherited hours in the live panel', function () {
    $course = Course::factory()->create(['total_hours' => 36]);
    $actor = ($this->actor)(['view_any_batch', 'create_batch', 'view_batch', 'create_enrollment']);
    $page = Livewire::actingAs($actor)->test(CreateBatch::class)->fillForm(['course_id' => $course->getKey(), 'code' => 'CHAIN-B'])
        ->assertSee(__('enrollment.inherited_from', ['course' => $course->name()]))
        ->callAction(TestAction::make('createAndEnroll')->schemaComponent('form-actions', 'form'));
    $batch = Batch::query()->where('code', 'CHAIN-B')->sole();
    $page->assertRedirect(EnrollAndCollect::getUrl(['batch' => $batch->getKey()]));
    expect($batch->effective_total_hours)->toBe(36)->and(Enrollment::query()->count())->toBe(0);
});

it('shows a real surname reminder only to staff with student read access and handles a zero price override', function () {
    $existing = Student::factory()->create(['student_code' => 'STU-SIBLING', 'last_name' => 'UniqueSiblingName']);
    $actor = ($this->actor)(['view_any_student', 'create_student', 'view_student']);
    Livewire::actingAs($actor)->test(CreateStudent::class)->fillForm(['last_name' => 'UniqueSiblingName'])
        ->assertSee(__('enrollment.surname_reminder', ['code' => 'STU-SIBLING']));
    $denied = ($this->actor)(['view_any_student', 'create_student']);
    Livewire::actingAs($denied)->test(CreateStudent::class)->fillForm(['last_name' => 'UniqueSiblingName'])->assertDontSee('STU-SIBLING');
    $course = Course::factory()->create(['default_price' => '1450.125']);
    $pricer = ($this->actor)(['view_any_batch', 'create_batch', 'manage_pricing']);
    Livewire::actingAs($pricer)->test(CreateBatch::class)->fillForm(['course_id' => $course->getKey(), 'price' => '0'])
        ->assertSee('0.000')->assertDontSee('1,450.125');
});

it('ignores malformed enrollment hints rather than casting them into selections', function (mixed $hint) {
    $actor = ($this->actor)(['create_enrollment', 'view_student', 'view_batch']);
    Livewire::actingAs($actor)->withQueryParams(['student' => $hint, 'batch' => $hint])
        ->test(EnrollAndCollect::class)->assertSet('data.student_id', null)->assertSet('data.batch_id', null);
    expect(Enrollment::query()->count())->toBe(0)->and(Charge::query()->count())->toBe(0);
})->with([['-1'], ['1abc'], [['1']], ['999999999999999999999999999'], ['999999'], ['0']]);

it('ignores forbidden student hints and closed batch hints without selected label leakage', function () {
    $student = Student::factory()->create(['first_name' => 'HintSecretFirst', 'last_name' => 'HintSecretLast']);
    $batch = Batch::factory()->completed()->create();
    $actor = ($this->actor)(['create_enrollment', 'view_batch']);
    $page = Livewire::actingAs($actor)->withQueryParams(['student' => $student->getKey(), 'batch' => $batch->getKey()])
        ->test(EnrollAndCollect::class)->assertSet('data.student_id', null)->assertSet('data.batch_id', null)
        ->assertDontSee('HintSecretFirst')->assertDontSee('HintSecretLast');
    $field = $page->instance()->form->getComponent('student_id');
    expect($field->getState())->toBeNull()->and($field->getOptionLabel())->toBeNull();
    expect(Enrollment::query()->count())->toBe(0);
});

it('submits valid hints through the ordinary enrollment and billing action and refuses missing access', function () {
    $student = Student::factory()->create();
    $batch = Batch::factory()->active()->create();
    $permissions = ['view_student', 'view_batch'];
    $actor = ($this->actor)([...$permissions, 'create_enrollment']);
    Livewire::actingAs($actor)->withQueryParams(['student' => $student->getKey(), 'batch' => $batch->getKey()])
        ->test(EnrollAndCollect::class)->call('confirm')->assertNotified(__('collect.confirmed'));
    expect(Enrollment::query()->where('student_id', $student->getKey())->where('batch_id', $batch->getKey())->count())->toBe(1)
        ->and(Charge::query()->count())->toBe(1)->and(Payment::query()->count())->toBe(0);
    $denied = ($this->actor)($permissions);
    $this->actingAs($denied)->get(EnrollAndCollect::getUrl(['student' => $student->getKey(), 'batch' => $batch->getKey()]))->assertForbidden();
});

it('rechecks permission and batch eligibility when submitting a hinted enrollment', function (string $change) {
    $student = Student::factory()->create();
    $batch = Batch::factory()->active()->create();
    $actor = ($this->actor)(['create_enrollment', 'view_student', 'view_batch']);
    $page = Livewire::actingAs($actor)->withQueryParams(['student' => $student->getKey(), 'batch' => $batch->getKey()])
        ->test(EnrollAndCollect::class)->assertSet('data.student_id', $student->getKey());
    if ($change === 'permission') {
        $actor->revokePermissionTo('create_enrollment');
    } else {
        $batch->update(['status' => BatchStatus::Completed]);
    }
    if ($change === 'permission') {
        $page->call('confirm')->assertForbidden();
    } else {
        $page->call('confirm')->assertNotified(__('enrollment.batch_closed'));
    }
    expect(Enrollment::query()->count())->toBe(0)->and(Charge::query()->count())->toBe(0);
})->with(['permission', 'batch']);

it('filters bills receipts and certificates to the selected student using real receivers', function () {
    $student = Student::factory()->create();
    $own = Enrollment::factory()->for($student)->create();
    $other = Enrollment::factory()->create();
    $bill = Charge::factory()->for($own)->create();
    $otherBill = Charge::factory()->for($other)->create();
    $receipt = Payment::factory()->create(['student_id' => $student->getKey()]);
    $otherReceipt = Payment::factory()->create();
    $certificate = StudentCertificate::factory()->for($own)->create();
    $otherCertificate = StudentCertificate::factory()->for($other)->create();
    $actor = ($this->actor)(['view_student', 'view_any_charge', 'view_any_payment', 'view_any_student_certificate']);
    Livewire::actingAs($actor)->withQueryParams(['filters' => ['student' => ['id' => $student->getKey()]]])->test(ListCharges::class)
        ->assertCanSeeTableRecords([$bill])->assertCanNotSeeTableRecords([$otherBill]);
    Livewire::actingAs($actor)->withQueryParams(['filters' => ['student' => ['id' => $student->getKey()]]])->test(ListPayments::class)
        ->assertCanSeeTableRecords([$receipt])->assertCanNotSeeTableRecords([$otherReceipt]);
    Livewire::actingAs($actor)->withQueryParams(['filters' => ['student' => ['id' => $student->getKey()]]])->test(ListStudentCertificates::class)
        ->assertCanSeeTableRecords([$certificate])->assertCanNotSeeTableRecords([$otherCertificate]);
    $denied = ($this->actor)(['view_any_charge', 'view_any_payment', 'view_any_student_certificate']);
    Livewire::actingAs($denied)->test(ListCharges::class)->filterTable('student', ['id' => $student->getKey()])
        ->assertCanNotSeeTableRecords([$bill, $otherBill]);
    Livewire::actingAs($denied)->test(ListPayments::class)->filterTable('student', ['id' => $student->getKey()])
        ->assertCanNotSeeTableRecords([$receipt, $otherReceipt]);
    Livewire::actingAs($denied)->test(ListStudentCertificates::class)->filterTable('student', ['id' => $student->getKey()])
        ->assertCanNotSeeTableRecords([$certificate, $otherCertificate]);
});

it('renders instructor identities in one batched profile read at two roster sizes', function () {
    $batch = Batch::factory()->active()->create();
    $actor = ($this->actor)(['view_batch']);
    foreach ([2, 8] as $size) {
        while ($batch->instructors()->count() < $size) {
            $instructor = User::factory()->create();
            StaffProfile::factory()->for($instructor)->instructor()->create(['job_title' => 'BatchedProfileTitle']);
            $batch->instructors()->attach($instructor, ['assigned_hours' => 10]);
        }
        $statements = captureStatements();
        Livewire::actingAs($actor)->test(InstructorsRelationManager::class,
            ['ownerRecord' => $batch, 'pageClass' => ViewBatch::class])->assertSee('BatchedProfileTitle');
        $reads = collect($statements)->filter(fn (array $statement): bool => str_starts_with($statement['sql'], 'select') && str_contains($statement['sql'], '`staff_profiles`'))->count();
        expect($reads)->toBe(1);
    }
});

it('shows real batch hero debt and global counts without filtering the badge totals', function () {
    $batch = Batch::factory()->active()->create(['capacity' => 1]);
    Enrollment::factory()->count(2)->for($batch)->create();
    $bill = Charge::factory()->for($batch->enrollments()->first())->create(['list_price' => '77.125', 'amount' => '77.125']);
    $actor = ($this->actor)(['view_any_batch', 'view_batch', 'view_any_charge', 'create_enrollment']);
    Livewire::actingAs($actor)->test(ViewBatch::class, ['record' => $batch->getKey()])
        ->assertSee('77.125')->assertSee(EnrollAndCollect::getUrl(['batch' => $batch->getKey()]), false);
    $denied = ($this->actor)(['view_any_batch', 'view_batch']);
    Livewire::actingAs($denied)->test(ViewBatch::class, ['record' => $batch->getKey()])
        ->assertDontSee('77.125')->assertDontSee(EnrollAndCollect::getUrl(['batch' => $batch->getKey()]), false);
    $page = Livewire::actingAs($actor)->test(ListBatches::class)->searchTable('nonexistent-batch')->assertCountTableRecords(0);
    $tabs = $page->instance()->getTabs();
    expect((int) $tabs['all']->getBadge())->toBe(1)->and((int) $tabs['over_capacity']->getBadge())->toBe(1);
});

it('preserves batch pagination and honest global badges without ghost rows on missing pages', function () {
    Batch::factory()->count(12)->active()->create();
    $actor = ($this->actor)(['view_any_batch']);
    $page = Livewire::actingAs($actor)->test(ListBatches::class)->set('tableRecordsPerPage', 5);
    $firstIds = $page->instance()->getTableRecords()->pluck('id');
    $page->call('gotoPage', 2, $page->instance()->getTablePaginationPageName());
    $records = $page->instance()->getTableRecords();
    expect($records->count())->toBe(5)->and($records->total())->toBe(12)->and($records->pluck('id')->intersect($firstIds)->count())->toBe(0)
        ->and((int) $page->instance()->getTabs()['all']->getBadge())->toBe(12);
    $page->call('gotoPage', 999, $page->instance()->getTablePaginationPageName());
    expect($page->instance()->getTableRecords()->count())->toBe(0)->and((int) $page->instance()->getTabs()['all']->getBadge())->toBe(12);
    $page->searchTable('never-matches-a-batch')->assertCountTableRecords(0);
    expect((int) $page->instance()->getTabs()['active']->getBadge())->toBe(12);
});

it('rounds capacity meters and changes tone only at the prototype threshold', function (int $active, int $capacity, ?int $percent, int $fill, string $tone) {
    $batch = Batch::factory()->create(['capacity' => $capacity]);
    $actor = ($this->actor)(['view_any_batch']);
    $column = Livewire::actingAs($actor)->test(ListBatches::class)->instance()->getTable()->getColumn('enrolment_load');
    $batch->setAttribute(Batch::ACTIVE_ENROLLMENTS_COUNT, $active);
    $column->record($batch);
    expect($column->getViewData()['tone'])->toBe($tone);
    $html = $column->toHtml();
    expect($html)->toContain('width="'.$fill.'" height="6" rx="3"');
    if ($percent !== null) {
        expect($html)->toContain(__('enrollment.meter_percent', ['value' => $percent]));
    } else {
        expect($html)->not->toContain('%');
    }
    if ($active > $capacity && $capacity > 0) {
        expect($html)->toContain(__('enrollment.over_capacity_warning'));
    }
})->with([
    'eighty stays green' => [8, 10, 80, 80, 'success'],
    'fraction rounds up' => [2, 3, 67, 67, 'success'],
    'below amber boundary' => [178, 200, 89, 89, 'success'],
    'half rounds into amber' => [179, 200, 90, 90, 'primary'],
    'over capacity stays flagged and clipped' => [4, 3, 133, 100, 'warning'],
    'zero capacity stays unlimited' => [3, 0, null, 0, 'success'],
]);
