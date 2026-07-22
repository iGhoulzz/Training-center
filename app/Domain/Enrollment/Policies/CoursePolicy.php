<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Policies;

use App\Domain\Enrollment\Models\Course;
use App\Models\User;

/**
 * Authorization for the course catalogue.
 *
 * Purely permission-based. A course carries no authorization weight of its own,
 * so there is no rank check and no ownership rule here.
 *
 * The grants are seeded by RolePermissionSeeder and the shape is deliberate:
 *
 *   - super_admin, admin: the full set. The catalogue is what the centre sells,
 *                         so defining it is an administrative act.
 *   - staff:              view_any_course and view_course only. Front-desk staff
 *                         answer "do you run English B1, and how many hours is
 *                         it" all day; they do not decide what the centre
 *                         offers, so they hold NO create, update or delete.
 *   - student:            nothing. The portal (phase 3) is a separate panel with
 *                         a separate guard; this policy governs the staff
 *                         dashboard only.
 *
 * There is no deleteAny(): CourseResource registers no bulk actions. Filament
 * authorizes a bulk action once against the *Any method and never consults the
 * per-record one, so leaving it undefined makes any bulk delete added later fail
 * closed until someone decides the rule on purpose. See docs/ENGINEERING.md,
 * "Bulk actions cannot be authorized per record".
 *
 * delete() does NOT check for existing batches. That refusal belongs to the
 * database — batches.course_id is restrictOnDelete — because a policy check
 * races: the batch can be created between the check passing and the delete
 * running. The constraint cannot be raced, and CourseTest asserts it fires.
 */
class CoursePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('view_any_course');
    }

    public function view(User $actor, Course $course): bool
    {
        return $actor->can('view_course');
    }

    public function create(User $actor): bool
    {
        return $actor->can('create_course');
    }

    public function update(User $actor, Course $course): bool
    {
        return $actor->can('update_course');
    }

    public function delete(User $actor, Course $course): bool
    {
        return $actor->can('delete_course');
    }
}
