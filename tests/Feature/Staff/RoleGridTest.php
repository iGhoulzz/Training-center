<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Filament\Resources\RoleResource\Pages\CreateRole;
use App\Domain\Staff\Filament\Resources\RoleResource\Pages\EditRole;
use App\Domain\Staff\Filament\Resources\RoleResource\Pages\ViewRole;
use App\Domain\Staff\Filament\Resources\RoleResource\PermissionGrid;
use App\Domain\Staff\Support\ActivityEvent;
use App\Models\Role;
use App\Models\User;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Database\Seeders\RolePermissionSeeder;
use Filament\Forms\Components\Checkbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * The role editor's permission grid (P4-T42): readable, and presentation only.
 *
 * The rule under most of these tests: UpdateRolePermissionsAction syncs a role to EXACTLY the
 * names it is given, so a permission the grid does not draw must survive a save of a role that
 * holds it, and must not appear on a role that lacks it.
 *
 * THE EDITOR IS NOT A SUPER ADMIN. Editing roles takes update_role plus assign_role (RolePolicy),
 * so the actor holds exactly those and the access a page needs; the super admin holds every
 * permission and would pass whichever ability the code asked for.
 */
uses(RefreshDatabase::class);

/**
 * A role holding exactly the named permissions.
 *
 * @param  list<string>  $permissions
 */
function gridRoleHolding(string $name, array $permissions): Role
{
    $role = Role::query()->create(['name' => $name, 'guard_name' => 'web']);
    app(SystemRoleWriter::class)->syncRolePermissions($role, $permissions);

    return $role;
}

/**
 * @param  list<string>  $permissions
 */
function gridActorHolding(array $permissions): User
{
    $user = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($user, gridRoleHolding('editor_'.uniqid(), $permissions)->name);

    return $user;
}

/** @return list<string> */
function gridHeldBy(Role $role): array
{
    return $role->permissions()->pluck('name')->sort()->values()->all();
}

/** The latest permission-change entry, as its added and removed lists. */
function gridLatestChange(): array
{
    $entry = Activity::query()->where('event', ActivityEvent::PERMISSIONS_CHANGED)->latest('id')->firstOrFail();

    return ['added' => $entry->properties['added'], 'removed' => $entry->properties['removed']];
}

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->editor = gridActorHolding(['access_admin_panel', 'view_any_role', 'view_role', 'update_role', 'create_role', 'assign_role']);
});

describe('the grid is readable', function () {
    it('draws human labels and no class path or guard, in English and Arabic', function (string $locale, array $expected, array $absent) {
        app()->setLocale($locale);

        $create = Livewire::actingAs($this->editor)->test(CreateRole::class)->assertOk();

        // What a person reads: the page without Livewire's snapshot, which names the page's own class (wire:name).
        $visible = html_entity_decode(preg_replace('/wire:(snapshot|effects|name)="[^"]*"/', '', $create->html()));

        foreach ($expected as $text) {
            expect($visible)->toContain($text);
        }

        foreach ($absent as $text) {
            expect($visible)->not->toContain($text);
        }
    })->with([
        'English' => ['en', ['Enrolment', 'Finance', 'Administration', 'Students', 'Student certificates', 'Payroll runs', 'Activity log', 'View list', 'Special permissions', 'Run payroll'], ['App\\Domain', 'App\\\\Domain', 'Guard', 'guard_name', 'Force Delete', 'Replicate', 'Reorder', 'Restore']],
        'Arabic' => ['ar', ['التسجيل', 'المالية', 'الإدارة', 'الطلاب', 'شهادات الطلاب', 'مسيرات الرواتب', 'سجل النشاط', 'عرض القائمة', 'صلاحيات خاصة', 'تشغيل مسير الرواتب'], ['App\\Domain', 'App\\\\Domain', 'Guard', 'guard_name', 'Force Delete', 'Replicate', 'Reorder', 'Restore']],
    ]);

    it('puts every custom permission in the grid exactly once', function () {
        $boxes = [];

        foreach (Livewire::actingAs($this->editor)->test(CreateRole::class)->instance()->form->getFlatComponents() as $field) {
            if ($field instanceof Checkbox && str_starts_with($field->getName(), 'permissions.')) {
                $boxes[] = substr($field->getName(), strlen('permissions.'));
            }
        }

        $custom = array_keys(FilamentShield::getCustomPermissions());

        // Not vacuous: forty, counted from config/filament-shield.php.
        expect($custom)->toHaveCount(40);

        foreach ($custom as $name) {
            expect(array_count_values($boxes)[$name] ?? 0)->toBe(1, "{$name} must have exactly one box");
        }

        // The seeder's own list too: nothing it creates for the editor is left un-grantable
        // except the seven hidden ones, which no policy or control uses.
        $seeded = DB::table('permissions')->pluck('name')->all();
        expect(array_values(array_diff($seeded, $boxes)))->toHaveCount(7);
    });

    it('draws a box for every permission a policy checks, and no box for one that none does', function () {
        $dormantRolePolicyAbilities = ['restore_role', 'force_delete_role', 'replicate_role', 'reorder_role'];

        $literals = [];

        foreach (File::allFiles(app_path()) as $file) {
            // A policy, or the rule a policy delegates to (EnrollmentUpdateRule holds update_enrollment).
            if (! str_ends_with($file->getFilename(), 'Policy.php') && ! str_ends_with($file->getFilename(), 'UpdateRule.php')) {
                continue;
            }

            foreach (token_get_all((string) file_get_contents($file->getRealPath())) as $token) {
                if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $value = trim($token[1], '\'"');

                    if (preg_match('/^[a-z]+(_[a-z]+)+$/', $value) === 1 && DB::table('permissions')->where('name', $value)->exists()) {
                        $literals[$value] = true;
                    }
                }
            }
        }

        $checked = array_values(array_diff(array_keys($literals), $dormantRolePolicyAbilities));
        $drawn = PermissionGrid::visibleNames();

        // Not vacuous: the scan found the policies' own checks.
        expect($checked)->toContain('view_any_student', 'delete_payroll_run', 'update_expense_category', 'create_role');

        // Every permission a policy checks has a box...
        expect(array_values(array_diff($checked, $drawn)))->toBe([]);

        // ...and every area box is one a policy checks (the special ones are acts the Actions and pages check).
        expect(array_values(array_diff(PermissionGrid::areaNames(), $checked)))->toBe([]);
    });

    it('hides only permissions nothing in the application can use', function () {
        $hidden = [];

        foreach (FilamentShield::getResources() as $resource) {
            foreach (array_keys($resource['permissions']) as $ability) {
                // Shield names a resource permission `{ability in snake case}_{model in snake case}`.
                $hidden[] = Str::snake($ability).'_'.Str::snake($resource['model']);
            }
        }

        foreach (FilamentShield::getPages() as $page) {
            array_push($hidden, ...array_keys($page['permissions']));
        }

        $hidden = array_values(array_diff($hidden, PermissionGrid::visibleNames()));

        // Not vacuous: restore, replicate, reorder, force delete and delete any are among them.
        expect($hidden)->toContain('restore_student', 'replicate_course', 'reorder_batch', 'force_delete_user', 'delete_any_charge', 'view_enroll_and_collect');

        // The role policy's four dormant abilities are the only ones named in code. Nothing calls
        // them: there is no restore, force-delete, replicate or reorder control anywhere.
        $dormant = ['restore_role', 'force_delete_role', 'replicate_role', 'reorder_role'];
        $referenced = [];

        foreach ([app_path(), resource_path('views'), base_path('routes')] as $directory) {
            foreach (File::allFiles($directory) as $file) {
                $source = $file->getExtension() === 'php' ? appSourceWithoutComments($file->getRealPath()) : (string) file_get_contents($file->getRealPath());

                foreach ($hidden as $name) {
                    if (str_contains($source, "'{$name}'") || str_contains($source, "\"{$name}\"")) {
                        $referenced[$name][] = $file->getFilename();
                    }
                }
            }
        }

        expect(array_diff(array_keys($referenced), $dormant))->toBe([])
            ->and(array_keys($referenced))->each->toBeIn($dormant);

        $controls = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (preg_match('/\b(RestoreAction|ForceDeleteAction|ReplicateAction|RestoreBulkAction|ForceDeleteBulkAction|TrashedFilter)\b|->reorderable\(/', appSourceWithoutComments($file->getRealPath())) === 1) {
                $controls[] = $file->getFilename();
            }
        }

        expect($controls)->toBe([], 'A control that restores, replicates, reorders or force-deletes now exists: draw its permission box.');
    });
});

describe('a save carries hidden permissions through', function () {
    it('keeps a hidden permission the role holds when an unrelated box changes', function () {
        $role = gridRoleHolding('desk', ['view_any_student', 'restore_role', 'force_delete_any_role']);

        Livewire::actingAs($this->editor)
            ->test(EditRole::class, ['record' => $role->getKey()])
            ->set('data.permissions.view_student', true)
            ->call('save')
            ->assertHasNoErrors();

        expect(gridHeldBy($role->fresh()))->toBe(['force_delete_any_role', 'restore_role', 'view_any_student', 'view_student'])
            ->and(gridLatestChange())->toBe(['added' => ['view_student'], 'removed' => []]);
    });

    it('does not add a hidden permission the role lacks', function () {
        $role = gridRoleHolding('desk', ['view_any_student']);

        Livewire::actingAs($this->editor)
            ->test(EditRole::class, ['record' => $role->getKey()])
            ->set('data.permissions.view_student', true)
            ->call('save')
            ->assertHasNoErrors();

        expect(gridHeldBy($role->fresh()))->toBe(['view_any_student', 'view_student']);
    });

    it('removes exactly the one box that was unticked, and keeps the hidden ones', function () {
        $role = gridRoleHolding('desk', ['view_any_student', 'view_student', 'reorder_role']);

        Livewire::actingAs($this->editor)
            ->test(EditRole::class, ['record' => $role->getKey()])
            ->set('data.permissions.view_student', false)
            ->call('save')
            ->assertHasNoErrors();

        expect(gridHeldBy($role->fresh()))->toBe(['reorder_role', 'view_any_student'])
            ->and(gridLatestChange())->toBe(['added' => [], 'removed' => ['view_student']]);
    });

    it('cannot be made to add a permission that has no box by a crafted payload', function () {
        $role = gridRoleHolding('desk', ['view_any_student']);

        Livewire::actingAs($this->editor)
            ->test(EditRole::class, ['record' => $role->getKey()])
            ->set('data.permissions.restore_role', true)
            ->set('data.permissions.delete_any_user', true)
            ->call('save')
            ->assertHasNoErrors();

        expect(gridHeldBy($role->fresh()))->toBe(['view_any_student']);
    });

    it('lets only drawn names out of a submitted state, whatever the payload says', function () {
        // The form already drops keys that have no box; this pins the second layer on its own.
        $hidden = 'restore_role';
        $visible = PermissionGrid::visibleNames()[0];

        expect(PermissionGrid::visibleNames())->not->toContain($hidden)
            ->and(PermissionGrid::tickedNames([$hidden => true, 'made_up_permission' => true]))->toBe([])
            ->and(PermissionGrid::tickedNames([$visible => true, $hidden => true]))->toBe([$visible])
            ->and(PermissionGrid::tickedNames([$visible => false]))->toBe([]);
    });

    it('changes nothing, and writes no entry, when a role is saved untouched', function () {
        $role = gridRoleHolding('desk', ['view_any_student', 'restore_role']);
        $entriesBefore = Activity::query()->where('event', ActivityEvent::PERMISSIONS_CHANGED)->count();

        Livewire::actingAs($this->editor)
            ->test(EditRole::class, ['record' => $role->getKey()])
            ->call('save')
            ->assertHasNoErrors();

        expect(gridHeldBy($role->fresh()))->toBe(['restore_role', 'view_any_student'])
            ->and(Activity::query()->where('event', ActivityEvent::PERMISSIONS_CHANGED)->count())->toBe($entriesBefore);
    });

    it('creates a role with exactly the ticked boxes, on the panel\'s guard', function () {
        Livewire::actingAs($this->editor)
            ->test(CreateRole::class)
            ->fillForm(['name' => 'trainee', 'permissions' => ['view_any_student' => true, 'view_student' => true, 'create_student' => false]])
            ->call('create')
            ->assertHasNoErrors();

        $role = Role::query()->where('name', 'trainee')->firstOrFail();

        expect(gridHeldBy($role))->toBe(['view_any_student', 'view_student'])
            ->and($role->guard_name)->toBe('web');
    });
});

describe('the protections are unchanged', function () {
    it('refuses to edit the super admin role, and a role the actor holds', function () {
        $super = Role::query()->where('name', 'super_admin')->firstOrFail();

        Livewire::actingAs($this->editor)->test(EditRole::class, ['record' => $super->getKey()])->assertForbidden();

        $held = Role::query()->where('name', $this->editor->roles()->first()->name)->firstOrFail();

        Livewire::actingAs($this->editor)->test(EditRole::class, ['record' => $held->getKey()])->assertForbidden();
    });

    it('refuses an actor one permission short of editing roles', function () {
        $short = gridActorHolding(['access_admin_panel', 'view_any_role', 'view_role', 'update_role']);
        $role = gridRoleHolding('desk', ['view_any_student']);

        Livewire::actingAs($short)->test(EditRole::class, ['record' => $role->getKey()])->assertForbidden();
    });

    it('shows a role read-only, ticked as held, with no select-all switch', function () {
        $role = gridRoleHolding('desk', ['view_any_student', 'restore_role']);

        $view = Livewire::actingAs($this->editor)->test(ViewRole::class, ['record' => $role->getKey()])->assertOk();

        expect(array_keys(array_filter($view->get('data.permissions'))))->toBe(['view_any_student']);

        foreach ($view->instance()->form->getFlatComponents() as $field) {
            if ($field instanceof Checkbox) {
                expect($field->isDisabled())->toBeTrue();
            }
        }

        expect($view->instance()->form->getFlatComponents())->not->toHaveKey('select_all');
    });
});
