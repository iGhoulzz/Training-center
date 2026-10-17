<?php

declare(strict_types=1);

use App\Domain\Enrollment\Filament\Resources\StudentResource;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Filament\Resources\ChargeResource\Pages\ListCharges;
use App\Domain\Finance\Filament\Resources\ChargeResource\Pages\ViewCharge;
use App\Domain\Finance\Models\Charge;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The Charges list and the charge view (P4-T32: QA P3-8 and P3-16)
|--------------------------------------------------------------------------
|
| P3-8: the billed amount already says LYD, and its description read
| "billed LYD", so the cell read "1500.000 LYD billed LYD".
|
| P3-16: the charge view named the enrolment, the batch and the course and
| never the student who owes the bill.
|
| EVERY ACTOR HOLDS EXACTLY THE PERMISSIONS NAMED, NEVER A ROLE.
*/
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->actor = function (array $permissions): User {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->givePermissionTo(['access_admin_panel', ...$permissions]);
        expect($actor->roles()->exists())->toBeFalse();

        return $actor;
    };

    // A bill for a named student, so the view has a name and a code to show.
    $this->billFor = function (string $code, string $first, string $last): Charge {
        $student = Student::factory()->create(['student_code' => $code, 'first_name' => $first, 'last_name' => $last]);

        return Charge::factory()->for(Enrollment::factory()->for($student))->create(['list_price' => '1500.000']);
    };

    // The page text with its markup and the Livewire snapshot taken out.
    $this->textOf = fn (string $html): string => preg_replace(
        '/\s+/u',
        ' ',
        strip_tags(preg_replace('/\s+wire:snapshot="[^"]*"/', '', $html)),
    );

    $this->html = fn ($page): string => preg_replace('/\s+wire:snapshot="[^"]*"/', '', $page->html());
});

/*
|--------------------------------------------------------------------------
| P3-8 — the amount does not say LYD twice
|--------------------------------------------------------------------------
*/

it('describes the billed amount as just "billed", the amount itself carrying the currency', function () {
    ($this->billFor)('STU-0042', 'Amina', 'Zarrouk');
    $actor = ($this->actor)(['view_any_charge']);

    $text = ($this->textOf)(Livewire::actingAs($actor)->test(ListCharges::class)->html());

    expect($text)->toContain('1500.000 LYD billed')
        ->not->toContain('billed LYD');
});

/*
|--------------------------------------------------------------------------
| P3-16 — the charge view names the student
|--------------------------------------------------------------------------
*/

it('shows the student the bill is for, by code and name, linked for an actor who may open students', function () {
    $charge = ($this->billFor)('STU-0042', 'Amina', 'Zarrouk');
    $actor = ($this->actor)(['view_any_charge', 'view_charge', 'view_student', 'view_any_student']);
    $link = 'href="'.e(StudentResource::getUrl('index', ['student' => $charge->enrollment->student_id])).'"';

    $page = Livewire::actingAs($actor)->test(ViewCharge::class, ['record' => $charge->getKey()]);

    expect(($this->textOf)($page->html()))->toContain('Student STU-0042 — Amina Zarrouk')
        ->and(($this->html)($page))->toContain($link);
});

it('still shows a deleted student by code and name, with no link to them', function () {
    $charge = ($this->billFor)('STU-0042', 'Amina', 'Zarrouk');
    $student = $charge->enrollment->student;
    $actor = ($this->actor)(['view_any_charge', 'view_charge', 'view_student', 'view_any_student']);
    $link = 'href="'.e(StudentResource::getUrl('index', ['student' => $student->getKey()])).'"';

    // The same actor and bill, linked while the student stands: the positive control.
    expect(($this->html)(Livewire::actingAs($actor)->test(ViewCharge::class, ['record' => $charge->getKey()])))
        ->toContain($link);

    $student->delete();
    expect(Student::query()->find($student->getKey()))->toBeNull();

    $page = Livewire::actingAs($actor)->test(ViewCharge::class, ['record' => $charge->getKey()]);

    expect(($this->textOf)($page->html()))->toContain('Student STU-0042 — Amina Zarrouk')
        ->and(($this->html)($page))->not->toContain($link);
});

it('shows the student without a link to an actor who may not open students', function (array $permissions) {
    $charge = ($this->billFor)('STU-0042', 'Amina', 'Zarrouk');
    $actor = ($this->actor)(['view_any_charge', 'view_charge', ...$permissions]);
    $link = 'href="'.e(StudentResource::getUrl('index', ['student' => $charge->enrollment->student_id])).'"';

    $page = Livewire::actingAs($actor)->test(ViewCharge::class, ['record' => $charge->getKey()]);

    expect(($this->textOf)($page->html()))->toContain('Student STU-0042 — Amina Zarrouk')
        ->and(($this->html)($page))->not->toContain($link);
})->with([
    'without view_student' => [['view_any_student']],
    'without view_any_student' => [['view_student']],
]);
