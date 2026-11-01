<?php

declare(strict_types=1);

namespace App\Domain\Staff\Filament\Resources\RoleResource;

use App\Models\Role;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\Lang;

/**
 * The role editor's permission screen (P4-T42): one row per area, one column per ability.
 *
 * WHAT IT REPLACES. Shield's stock editor listed every permission it could generate for
 * every resource, under the model's class path, with its own technical labels: 182 boxes
 * for roughly a hundred permissions, most of them for acts the application does not have
 * (restore, replicate, reorder, force delete). This is the same data, drawn so a person can
 * read it: human labels, the sidebar's headings, a column per ability, and a group of
 * special permissions with a sentence each.
 *
 * PRESENTATION ONLY. It changes no permission, no policy and no write path. The pages still
 * hand the result to UpdateRolePermissionsAction, which authorizes and logs it.
 *
 * THE ONE RULE THAT MATTERS: A HIDDEN PERMISSION IS CARRIED THROUGH. The Action syncs a role
 * to EXACTLY the names it is given, so a permission with no box would be stripped by the
 * next save of an unrelated field. namesToSave() therefore returns the ticked visible boxes
 * plus whatever the role holds that the grid does not show. A role that holds a hidden
 * permission keeps it; a role that lacks one is never given it, because only a ticked box
 * can add.
 *
 * WHAT IS SHOWN, AND WHY THE REST IS NOT. A box is drawn only for a permission some policy
 * checks (AREAS below, each entry read off the policy's own `can()` calls) or that the
 * config lists as a custom permission (SPECIAL below). The rest are Shield's generated
 * restore, restore_any, replicate, reorder, force_delete and force_delete_any for every
 * model, delete_any for every model, the create/edit/delete abilities of models whose
 * policy refuses them unconditionally, and the page and widget permissions, which nothing
 * checks (no page uses Shield's page trait; each page decides access in canAccess()).
 * RoleGridTest proves each of those: it fails if a hidden permission is referenced by
 * the application, or if a permission a policy checks has no box.
 */
final class PermissionGrid
{
    /** The columns of the area grid: the ability's permission prefix, and its label key. */
    public const ABILITIES = [
        'view_any' => 'view_any',
        'view' => 'view',
        'create' => 'create',
        'update' => 'update',
        'delete' => 'delete',
    ];

    /**
     * The areas, under the sidebar's headings. Each row is a label key, the model's snake-case
     * name (a permission is `{ability}_{subject}`) and the abilities that have a box.
     *
     * Every ability listed is checked by the model's policy, by a literal `can()` call (a
     * role's three writes go through RolePolicy::mayMutate()); one that is not listed is not.
     * The enrolment and staff-certificate rows are custom permissions in config/filament-shield.php,
     * because those models have no Filament resource of their own.
     *
     * @var array<string, array<string, array{subject: string, abilities: list<string>}>>
     */
    private const AREAS = [
        'front_desk' => [
            'enrollments' => ['subject' => 'enrollment', 'abilities' => ['view_any', 'view', 'create', 'update', 'delete']],
        ],
        'enrollment' => [
            'students' => ['subject' => 'student', 'abilities' => ['view_any', 'view', 'create', 'update', 'delete']],
            'batches' => ['subject' => 'batch', 'abilities' => ['view_any', 'view', 'create', 'update', 'delete']],
            'courses' => ['subject' => 'course', 'abilities' => ['view_any', 'view', 'create', 'update', 'delete']],
            'student_certificates' => ['subject' => 'student_certificate', 'abilities' => ['view_any', 'view']],
        ],
        'finance' => [
            'charges' => ['subject' => 'charge', 'abilities' => ['view_any', 'view']],
            'payments' => ['subject' => 'payment', 'abilities' => ['view_any', 'view', 'create']],
            'discounts' => ['subject' => 'discount', 'abilities' => ['view_any', 'view']],
            'expenses' => ['subject' => 'expense', 'abilities' => ['view_any', 'view', 'create']],
            'expense_categories' => ['subject' => 'expense_category', 'abilities' => ['view_any', 'view', 'create', 'update']],
            'payroll_runs' => ['subject' => 'payroll_run', 'abilities' => ['view_any', 'view', 'delete']],
            'staff_compensation' => ['subject' => 'staff_compensation', 'abilities' => ['view_any', 'view', 'create']],
        ],
        'publications' => [
            'articles' => ['subject' => 'article', 'abilities' => ['view_any', 'view', 'create', 'update']],
        ],
        'administration' => [
            'users' => ['subject' => 'user', 'abilities' => ['view_any', 'view', 'create', 'update', 'delete']],
            'roles' => ['subject' => 'role', 'abilities' => ['view_any', 'view', 'create', 'update', 'delete']],
            'staff_profiles' => ['subject' => 'staff_profile', 'abilities' => ['view_any', 'view', 'create', 'update', 'delete']],
            'staff_certificates' => ['subject' => 'staff_certificate', 'abilities' => ['view_any', 'view', 'create', 'update', 'delete']],
            'activity' => ['subject' => 'activity', 'abilities' => ['view_any', 'view']],
        ],
    ];

    /**
     * The special permissions: acts that are not a plain view, create, edit or delete.
     * Every custom permission in config/filament-shield.php that is not an area cell is here,
     * exactly once; a name added to the config and not here is shown under "Other", so nothing
     * becomes impossible to grant. Each has a label in `staff.permissions` and a sentence in
     * `staff.permission_help`.
     *
     * @var array<string, list<string>>
     */
    private const SPECIAL = [
        'access' => ['access_admin_panel', 'access_student_portal'],
        'enrolment' => [
            'assign_instructor', 'complete_enrollment', 'complete_assigned_batch_enrollment',
            'update_assigned_batch_enrollment', 'issue_student_certificate', 'replace_student_certificate',
            'revoke_student_certificate', 'issue_portal_credential', 'reset_portal_credential',
        ],
        'finance' => [
            'manage_pricing', 'apply_discount', 'adjust_charge', 'write_off_charge', 'reverse_payment',
            'reverse_expense', 'run_payroll', 'finalize_payroll', 'view_financial_report', 'export_financial_report',
        ],
        'publications' => ['publish_article', 'unpublish_article'],
        'administration' => ['assign_role', 'reset_user_password', 'manage_settings'],
        'portal' => ['view_own_student_record', 'view_own_enrollment', 'view_own_balance', 'view_own_certificate'],
    ];

    /** The form's state key for every box: `permissions.{name}` is true when ticked. */
    public const STATE = 'permissions';

    /**
     * Every permission name the grid shows, area cells first, then the special ones.
     *
     * @return list<string>
     */
    public static function visibleNames(): array
    {
        return array_values(array_unique([...self::areaNames(), ...self::specialNames()]));
    }

    /**
     * The area cells' permission names.
     *
     * @return list<string>
     */
    public static function areaNames(): array
    {
        $names = [];

        foreach (self::AREAS as $rows) {
            foreach ($rows as $row) {
                foreach ($row['abilities'] as $ability) {
                    $names[] = "{$ability}_{$row['subject']}";
                }
            }
        }

        return $names;
    }

    /**
     * The special permissions' names, including any the config lists that the table above does not.
     *
     * @return list<string>
     */
    public static function specialNames(): array
    {
        return [...array_merge(...array_values(self::SPECIAL)), ...self::unlistedCustomNames()];
    }

    /**
     * Custom permissions in the config that neither an area cell nor SPECIAL accounts for.
     *
     * @return list<string>
     */
    private static function unlistedCustomNames(): array
    {
        $known = [...self::areaNames(), ...array_merge(...array_values(self::SPECIAL))];

        return array_values(array_diff(array_keys(FilamentShield::getCustomPermissions() ?? []), $known));
    }

    /**
     * The state a role's form starts from: every visible permission it holds, ticked.
     *
     * @return array<string, bool>
     */
    public static function stateFor(Role $role): array
    {
        $held = $role->permissions()->pluck('name')->all();

        return collect(self::visibleNames())
            ->filter(fn (string $name): bool => in_array($name, $held, true))
            ->mapWithKeys(fn (string $name): array => [$name => true])
            ->all();
    }

    /**
     * The visible permissions the form has ticked, from its submitted state.
     *
     * Only names the grid shows can come out: a crafted payload naming any other permission is
     * ignored here, so a box that is not drawn can never ADD a permission.
     *
     * @param  array<array-key, mixed>  $state
     * @return list<string>
     */
    public static function tickedNames(array $state): array
    {
        return array_values(array_filter(
            self::visibleNames(),
            fn (string $name): bool => filter_var($state[$name] ?? false, FILTER_VALIDATE_BOOLEAN),
        ));
    }

    /**
     * What a role is synced to: the ticked boxes plus the permissions it holds that no box shows.
     *
     * The second half is the safety rule in the class docblock. Read from the database at the
     * moment of the save, not from the form, which never knew those names.
     *
     * @param  list<string>  $ticked
     * @return list<string>
     */
    public static function namesToSave(?Role $role, array $ticked): array
    {
        $hiddenHeld = $role === null
            ? []
            : array_values(array_diff($role->permissions()->pluck('name')->all(), self::visibleNames()));

        return array_values(array_unique([...$ticked, ...$hiddenHeld]));
    }

    /** The "select all" switch: ticks and clears every box the grid shows, and nothing it does not. */
    public static function selectAll(): Component
    {
        return Toggle::make('select_all')
            ->onIcon('heroicon-s-shield-check')
            ->offIcon('heroicon-s-shield-exclamation')
            ->label(__('filament-shield::filament-shield.field.select_all.name'))
            ->helperText(__('filament-shield::filament-shield.field.select_all.message'))
            ->hiddenOn('view')
            ->live()
            ->afterStateUpdated(fn (Set $set, bool $state) => $set(self::STATE, array_fill_keys(self::visibleNames(), $state)))
            ->dehydrated(false);
    }

    /**
     * Whether every box the grid shows is ticked, for the select-all switch to mirror.
     *
     * @param  array<array-key, mixed>|null  $state
     */
    public static function allTicked(?array $state): bool
    {
        return count(self::tickedNames($state ?? [])) === count(self::visibleNames());
    }

    /**
     * The cards of the screen: one per sidebar heading, then the special permissions.
     *
     * @return list<Component>
     */
    public static function sections(): array
    {
        $sections = [];

        foreach (self::AREAS as $group => $rows) {
            $sections[] = Section::make(__("navigation.{$group}"))
                ->columnSpanFull()
                ->extraAttributes(['class' => 'tc-perm-grid'])
                ->schema([
                    self::headerRow(),
                    ...array_map(
                        fn (string $key, array $row): Component => self::areaRow($key, $row['subject'], $row['abilities']),
                        array_keys($rows),
                        array_values($rows),
                    ),
                ]);
        }

        $sections[] = self::specialSection();

        return $sections;
    }

    private static function headerRow(): Component
    {
        return Grid::make(['default' => 7])
            ->extraAttributes(['class' => 'tc-perm-row tc-perm-head'])
            ->schema([
                Text::make('')->columnSpan(2),
                ...array_map(
                    fn (string $ability): Component => Text::make(__("staff.grid.abilities.{$ability}"))
                        ->extraAttributes(['class' => 'tc-perm-cell']),
                    array_keys(self::ABILITIES),
                ),
            ]);
    }

    /**
     * @param  list<string>  $abilities
     */
    private static function areaRow(string $key, string $subject, array $abilities): Component
    {
        $area = __("staff.grid.rows.{$key}");

        return Grid::make(['default' => 7])
            ->extraAttributes(['class' => 'tc-perm-row'])
            ->schema([
                Text::make($area)->weight('medium')->columnSpan(2),
                ...array_map(
                    fn (string $ability): Component => in_array($ability, $abilities, true)
                        ? self::box("{$ability}_{$subject}")
                            ->label(__('staff.grid.cell', ['area' => $area, 'ability' => __("staff.grid.abilities.{$ability}")]))
                            ->hiddenLabel()
                            ->extraAttributes(['class' => 'tc-perm-cell'])
                        : Text::make(__('details.empty'))
                            ->color('gray')
                            ->extraAttributes(['class' => 'tc-perm-cell tc-perm-none']),
                    array_keys(self::ABILITIES),
                ),
            ]);
    }

    private static function specialSection(): Component
    {
        $groups = self::SPECIAL;

        if (self::unlistedCustomNames() !== []) {
            $groups['other'] = self::unlistedCustomNames();
        }

        return Section::make(__('staff.grid.special_heading'))
            ->description(__('staff.grid.special_hint'))
            ->columnSpanFull()
            ->schema(array_map(
                fn (string $group, array $names): Component => Fieldset::make(__("staff.grid.special_groups.{$group}"))
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema(array_map(
                        fn (string $name): Component => self::box($name)
                            ->label(__("staff.permissions.{$name}"))
                            ->helperText(Lang::has("staff.permission_help.{$name}") ? __("staff.permission_help.{$name}") : null),
                        $names,
                    )),
                array_keys($groups),
                array_values($groups),
            ));
    }

    /** One permission's checkbox. It mirrors itself into the select-all switch. */
    private static function box(string $name): Checkbox
    {
        return Checkbox::make(self::STATE.'.'.$name)
            ->live()
            ->afterStateUpdated(fn (Get $get, Set $set) => $set('select_all', self::allTicked($get(self::STATE))));
    }
}
