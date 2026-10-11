<?php

declare(strict_types=1);

use App\Domain\Enrollment\Filament\Pages\CertificateIssuanceQueue;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\StudentCertificate;
use App\Domain\Finance\Models\Charge;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $role = Role::query()->create(['name' => 'certificate_issuer', 'guard_name' => 'web']);
    app(SystemRoleWriter::class)->syncRolePermissions($role, ['access_admin_panel', 'issue_student_certificate']);
    $this->issuer = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($this->issuer, 'certificate_issuer');
    $this->denied = User::factory()->create(['is_active' => true]);
    $viewerRole = Role::query()->create(['name' => 'queue_denied', 'guard_name' => 'web']);
    app(SystemRoleWriter::class)->syncRolePermissions($viewerRole, ['access_admin_panel']);
    app(SystemRoleWriter::class)->assignRoles($this->denied, 'queue_denied');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('opens and shows navigation with exactly the issue permission', function () {
    expect($this->issuer->getAllPermissions()->pluck('name')->sort()->values()->all())
        ->toBe(['access_admin_panel', 'issue_student_certificate']);
    $this->actingAs($this->issuer)->get('/admin/certificate-issuance-queue')->assertSuccessful();
    expect(CertificateIssuanceQueue::canAccess())->toBeTrue()
        ->and(collect(Filament::getNavigation())->flatMap(fn ($group) => $group->getItems())
            ->map(fn ($item): string => $item->getUrl())->all())->toContain(CertificateIssuanceQueue::getUrl());
});

it('refuses the page and navigation with one fewer permission', function () {
    $this->actingAs($this->denied)->get('/admin/certificate-issuance-queue')->assertForbidden();
    expect(CertificateIssuanceQueue::canAccess())->toBeFalse()
        ->and(collect(Filament::getNavigation())->flatMap(fn ($group) => $group->getItems())
            ->map(fn ($item): string => $item->getUrl())->all())->not->toContain(CertificateIssuanceQueue::getUrl());
    Livewire::actingAs($this->denied)->test(CertificateIssuanceQueue::class)->assertForbidden();
});

it('lists completed enrolments without a valid certificate including revoked and replaced history', function () {
    $ready = Enrollment::factory()->completed()->create();
    $revoked = Enrollment::factory()->completed()->create();
    StudentCertificate::factory()->revoked()->for($revoked)->create();
    $replaced = Enrollment::factory()->completed()->create();
    StudentCertificate::factory()->replaced()->for($replaced)->create();
    $issued = Enrollment::factory()->completed()->create();
    StudentCertificate::factory()->for($issued)->create();
    $active = Enrollment::factory()->create();
    $withdrawn = Enrollment::factory()->withdrawn()->create();

    Livewire::actingAs($this->issuer)->test(CertificateIssuanceQueue::class)
        ->assertCanSeeTableRecords([$ready, $revoked, $replaced])
        ->assertCanNotSeeTableRecords([$issued, $active, $withdrawn])
        ->assertCountTableRecords(3);
});

it('renders the student course batch and Tripoli completion date in the actual table markup', function () {
    $enrollment = Enrollment::factory()->completed()->create([
        'enrolled_at' => '2026-10-01 10:00:00',
        'completed_at' => '2026-10-10 22:30:00',
    ]);
    $response = $this->actingAs($this->issuer)->get('/admin/certificate-issuance-queue')->assertSuccessful();
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    $text = $xpath->evaluate('string(//tbody)');

    expect($text)->toContain($enrollment->student->full_name, $enrollment->student->student_code,
        $enrollment->batch->course->name(), $enrollment->batch->code,
        'Oct 11, 2026')->not->toContain('Oct 10, 2026');
});

it('links each row to the existing issue form and issues a debtor with only the issue grant', function () {
    $owing = Enrollment::factory()->completed()->create();
    Charge::factory()->for($owing)->create(['list_price' => '750.000']);
    $other = Enrollment::factory()->completed()->create();
    $response = $this->actingAs($this->issuer)->get('/admin/certificate-issuance-queue')->assertSuccessful();
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    $links = $xpath->query('//tbody//a[contains(@href, "action=issue")]');
    expect($links)->toHaveCount(2);
    $url = $links->item(0)->getAttribute('href');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $parameters);
    expect(parse_url($url, PHP_URL_PATH))->toBe('/admin/certificate-issuance-queue')
        ->and($parameters)->toBe(['action' => 'issue', 'actionArguments' => ['enrollment_id' => (string) $owing->id]])
        ->and($this->issuer->can('view_any_student_certificate'))->toBeFalse();

    $destination = $this->get($url)->assertSuccessful();
    $destinationDocument = new DOMDocument;
    @$destinationDocument->loadHTML($destination->getContent());
    $initializers = (new DOMXPath($destinationDocument))->query('//*[@*[name()="wire:init"]]');
    expect(collect($initializers)->map(fn (DOMElement $element): string => $element->getAttribute('wire:init'))
        ->contains(fn (string $initializer): bool => str_contains($initializer, 'mountAction(')
            && str_contains($initializer, 'issue') && str_contains($initializer, 'enrollment_id')))->toBeTrue();

    // Filament mounts URL actions through this rendered wire:init on page load.
    $component = Livewire::actingAs($this->issuer)->withQueryParams($parameters)
        ->test(CertificateIssuanceQueue::class)
        ->assertSet('defaultAction', 'issue');
    $page = $component->instance();
    $component->call('mountAction', $page->defaultAction, $page->defaultActionArguments, $page->getDefaultActionUrlContext())
        ->assertSet('mountedActions.0.name', 'issue')
        ->assertSet('mountedActions.0.context.mountedFromUrl', true)
        ->assertActionDataSet(['enrollment_id' => $owing->id])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertCanNotSeeTableRecords([$owing])
        ->assertCanSeeTableRecords([$other]);

    $this->assertDatabaseHas('student_certificates', ['enrollment_id' => $owing->id, 'issued_by' => $this->issuer->id, 'status' => 'valid']);
    $this->assertDatabaseMissing('student_certificates', ['enrollment_id' => $other->id]);
});

it('gives debtors the same unqualified row action and no balance column', function () {
    $owing = Enrollment::factory()->completed()->create();
    Charge::factory()->for($owing)->create(['list_price' => '750.000']);
    $clear = Enrollment::factory()->completed()->create();
    $component = Livewire::actingAs($this->issuer)->test(CertificateIssuanceQueue::class)
        ->assertCanSeeTableRecords([$owing, $clear]);
    $table = $component->instance()->getTable();
    expect(array_keys($table->getColumns()))->not->toContain('outstanding_balance', 'balance');
    foreach ([$owing, $clear] as $enrollment) {
        $action = $table->getAction('issueCertificate')->record($enrollment);
        expect($action->isDisabled())->toBeFalse()
            ->and($action->isVisible())->toBeTrue()
            ->and($action->getLabel())->toBe(__('certificates.issue'));
    }
});

it('refuses an ineligible enrolment supplied in the URL arguments', function () {
    $active = Enrollment::factory()->create();
    Livewire::actingAs($this->issuer)->test(CertificateIssuanceQueue::class)
        ->mountAction('issue', ['enrollment_id' => $active->id])
        ->assertActionDataSet(['enrollment_id' => $active->id])
        ->callMountedAction()
        ->assertNotified(__('certificates.enrollment_not_completed'));
    $this->assertDatabaseMissing('student_certificates', ['enrollment_id' => $active->id]);
});

it('refuses a stale queue link after another certificate was issued', function () {
    $enrollment = Enrollment::factory()->completed()->create();
    $component = Livewire::actingAs($this->issuer)->test(CertificateIssuanceQueue::class)
        ->mountAction('issue', ['enrollment_id' => $enrollment->id]);
    StudentCertificate::factory()->for($enrollment)->create();
    $component->callMountedAction()->assertNotified(__('certificates.certificate_already_issued'));
    expect(StudentCertificate::query()->where('enrollment_id', $enrollment->id)->count())->toBe(1);
});

it('shows an empty queue once no enrolments need issuance', function () {
    Livewire::actingAs($this->issuer)->test(CertificateIssuanceQueue::class)
        ->assertCountTableRecords(0)->assertSee(__('certificates.queue_empty'));
});
