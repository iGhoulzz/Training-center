<?php

declare(strict_types=1);

use App\Domain\Enrollment\Filament\Resources\StudentResource\Pages\ListStudents;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Staff\Actions\IssuePortalCredentialAction;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Exceptions\EmailAlreadyRegisteredException;
use App\Domain\Staff\Exceptions\StudentHasNoEmailException;
use App\Domain\Staff\Exceptions\StudentHasPortalAccountException;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('issues a temporary portal credential with exactly the student role', function (): void {
    // Replacing the literal student role in the Action with caller input must
    // make this fail: the credential issuer has no authority to create staff
    // accounts by another route.
    $actor = User::factory()->create();
    $actor->givePermissionTo('issue_portal_credential');
    $student = Student::factory()->create([
        'first_name' => 'Amal',
        'last_name' => 'Ibrahim',
        'email' => 'amal@example.test',
    ]);

    $plain = app(IssuePortalCredentialAction::class)->execute($actor->fresh(), $student);

    $account = User::query()->where('email', 'amal@example.test')->sole();

    expect($plain)->toHaveLength(16)
        ->and(Hash::check($plain, $account->password))->toBeTrue()
        ->and($account->must_change_password)->toBeTrue()
        ->and($account->roles()->pluck('name')->all())->toBe(['student'])
        ->and($student->fresh()->user_id)->toBe($account->getKey());
});

it('authorizes portal credential issuance inside the action', function (): void {
    $actor = User::factory()->create();
    $student = Student::factory()->create();

    expect(fn (): string => app(IssuePortalCredentialAction::class)->execute($actor, $student))
        ->toThrow(AuthorizationException::class);

    expect(User::query()->where('email', $student->email)->exists())->toBeFalse()
        ->and($student->fresh()->user_id)->toBeNull();
});

it('refuses issuance for a student who already has a portal account', function (): void {
    $actor = User::factory()->create();
    $actor->givePermissionTo('issue_portal_credential');
    $student = Student::factory()->withAccount()->create();

    expect(fn (): string => app(IssuePortalCredentialAction::class)->execute($actor->fresh(), $student))
        ->toThrow(StudentHasPortalAccountException::class);
});

it('refuses issuance for a student without an email address', function (): void {
    $actor = User::factory()->create();
    $actor->givePermissionTo('issue_portal_credential');
    $student = Student::factory()->create(['email' => null]);

    expect(fn (): string => app(IssuePortalCredentialAction::class)->execute($actor->fresh(), $student))
        ->toThrow(StudentHasNoEmailException::class);
});

it('refuses issuance when an active account holds the student email', function (): void {
    $actor = User::factory()->create();
    $actor->givePermissionTo('issue_portal_credential');
    $student = Student::factory()->create(['email' => 'existing@example.test']);
    User::factory()->create(['email' => $student->email]);

    expect(fn (): string => app(IssuePortalCredentialAction::class)->execute($actor->fresh(), $student))
        ->toThrow(EmailAlreadyRegisteredException::class);
});

it('refuses issuance when a soft-deleted account holds the student email', function (): void {
    $actor = User::factory()->create();
    $actor->givePermissionTo('issue_portal_credential');
    $student = Student::factory()->create(['email' => 'archived@example.test']);
    $account = User::factory()->create(['email' => $student->email]);
    $account->delete();
    User::creating(fn (): never => throw new RuntimeException('A soft-deleted email must refuse before account creation.'));

    expect(fn (): string => app(IssuePortalCredentialAction::class)->execute($actor->fresh(), $student))
        ->toThrow(EmailAlreadyRegisteredException::class);
});

it('refuses issuance when a non-student account holds the student email', function (): void {
    $actor = User::factory()->create();
    $actor->givePermissionTo('issue_portal_credential');
    $student = Student::factory()->create(['email' => 'staff@example.test']);
    $account = User::factory()->create(['email' => $student->email]);
    app(SystemRoleWriter::class)->assignRoles($account, 'staff');

    expect($account->roles()->pluck('name')->all())->toBe(['staff']);

    expect(fn (): string => app(IssuePortalCredentialAction::class)->execute($actor->fresh(), $student))
        ->toThrow(EmailAlreadyRegisteredException::class);
});

it('does not let an issue-only actor create a staff account', function (): void {
    $actor = User::factory()->create(['is_active' => true]);
    $actor->givePermissionTo('issue_portal_credential');

    $this->actingAs($actor)
        ->get('/admin/users/create')
        ->assertForbidden();

    expect(User::query()->where('email', 'unauthorized-staff@example.test')->exists())->toBeFalse();
});

it('does not translate a duplicate key failure from another unique index', function (): void {
    $actor = User::factory()->create();
    $actor->givePermissionTo('issue_portal_credential');
    $student = Student::factory()->create(['email' => 'other-index@example.test']);

    User::creating(function (): void {
        $previous = new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'X' for key 'users_some_other_unique'");
        $previous->errorInfo = ['23000', 1062, "Duplicate entry 'X' for key 'users_some_other_unique'"];

        throw (new UniqueConstraintViolationException(
            'mysql',
            'insert into `users` ...',
            [],
            $previous,
        ))->setIndex('users_some_other_unique');
    });

    expect(fn (): string => app(IssuePortalCredentialAction::class)->execute($actor->fresh(), $student))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('issues a portal credential through the student register action', function (): void {
    $actor = User::factory()->create();
    $actor->givePermissionTo(
        'access_admin_panel',
        'view_any_student',
        'view_student',
        'issue_portal_credential',
    );
    $student = Student::factory()->create(['email' => 'register@example.test']);

    Livewire::actingAs($actor->fresh())
        ->test(ListStudents::class)
        ->callTableAction('issuePortalCredential', $student)
        ->assertNotified(__('credentials.issued'));

    expect($student->fresh()->user)->not->toBeNull()
        ->and($student->fresh()->user->roles()->pluck('name')->all())->toBe(['student']);
});
