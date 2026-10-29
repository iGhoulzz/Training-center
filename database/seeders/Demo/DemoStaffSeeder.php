<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Enums\EmploymentType;
use App\Domain\Staff\Models\StaffProfile;
use App\Models\User;

/**
 * The people who work at the centre.
 *
 * MIRRORS the admin panel's two create pages, which write with a plain Eloquent
 * save and then hand roles to an Action:
 *
 * - CreateUser (app/Domain/Staff/Filament/Resources/UserResource/Pages/CreateUser.php,
 *   handleRecordCreation by default, then afterCreate()): the row, then roles through
 *   SyncUserRolesAction. The seeder has no signed-in actor, so roles go through
 *   SystemRoleWriter, the trusted path UserSeeder uses.
 * - CreateStaffProfile (.../StaffProfileResource/Pages/CreateStaffProfile.php, no
 *   override): a plain create of the profile row.
 *
 * Unlike the page, no temporary password is issued: every demo account gets the
 * one shared password, with must_change_password off, so the owner can sign in
 * as any of them to see the panel through that role's eyes.
 *
 * The owner account already exists (UserSeeder creates owner@example.test with
 * a placeholder password and must_change_password on). It is made usable here by
 * setting the shared password and clearing the flag; nothing else about it changes.
 */
final class DemoStaffSeeder extends DemoAreaSeeder
{
    /**
     * [key => [name, email, role, locale, job title, employment type, qualifications]].
     * A null employment type means the account has no staff profile.
     *
     * @var array<string, array{string, string, string, string, ?string, ?EmploymentType, ?string}>
     */
    private const PEOPLE = [
        'admin' => ['Nadia Farouk', 'admin@example.test', 'admin', 'ar', 'Centre administrator', EmploymentType::Administrative, 'BA Business Administration'],
        'frontdesk1' => ['Karim Suliman', 'frontdesk1@example.test', 'staff', 'en', 'Front desk', EmploymentType::Administrative, 'Customer service diploma'],
        'frontdesk2' => ['Mariam Zayed', 'frontdesk2@example.test', 'staff', 'ar', 'Front desk', EmploymentType::Administrative, 'Customer service diploma'],
        'omar' => ['Omar Khalil', 'omar@example.test', 'staff', 'en', 'English instructor', EmploymentType::Instructor, 'MA Applied Linguistics, CELTA'],
        'salma' => ['Salma Idris', 'salma@example.test', 'staff', 'ar', 'Technology instructor', EmploymentType::Instructor, 'BSc Computer Science'],
        'yousef' => ['Yousef Mansour', 'yousef@example.test', 'staff', 'en', 'Accounting instructor', EmploymentType::Instructor, 'CPA, MSc Accounting'],
        'hana' => ['Hana Belkacem', 'hana@example.test', 'staff', 'ar', 'Office skills instructor', EmploymentType::Instructor, 'Microsoft Office Specialist'],
        'rania' => ['Rania Mahdi', 'rania@example.test', 'staff', 'ar', 'Registrar', EmploymentType::Administrative, 'Records management certificate'],
        'tarek' => ['Tarek Bashir', 'tarek@example.test', 'staff', 'en', 'Accountant', EmploymentType::Administrative, 'BSc Accounting'],
        'huda' => ['Huda Saeed', 'huda@example.test', 'staff', 'en', 'Library and resources assistant', EmploymentType::Support, null],
    ];

    public function run(): void
    {
        $context = $this->context;

        $context->timeline->add($context->day(-184), 8, 'staff accounts', function () use ($context): void {
            $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();

            $context->as($owner, fn (): bool => $owner->update([
                'password' => DemoContext::PASSWORD,
                'must_change_password' => false,
                'locale' => 'en',
            ]));

            $context->users['owner'] = $owner->refresh();

            foreach (self::PEOPLE as $key => [$name, $email, $role, $locale, $jobTitle, $employment, $qualifications]) {
                $user = $context->as($owner, fn (): User => User::query()->create([
                    'name' => $name,
                    'email' => $email,
                    'password' => DemoContext::PASSWORD,
                    'locale' => $locale,
                    'is_active' => true,
                    'must_change_password' => false,
                ]));

                app(SystemRoleWriter::class)->assignRoles($user, $role);

                if ($employment !== null) {
                    $context->as($owner, fn (): StaffProfile => StaffProfile::query()->create([
                        'user_id' => $user->getKey(),
                        'phone' => sprintf('+218 92 000 %04d', count($context->users)),
                        'job_title' => $jobTitle,
                        'hire_date' => $context->day(-400 + count($context->users) * 20)->toDateString(),
                        'employment_type' => $employment,
                        'qualifications' => $qualifications,
                    ]));
                }

                // Roles were written on a fresh copy of the row; drop anything this
                // instance cached so its first permission check reads them.
                $context->users[$key] = $user->refresh()->unsetRelation('roles')->unsetRelation('permissions');
            }
        });
    }
}
