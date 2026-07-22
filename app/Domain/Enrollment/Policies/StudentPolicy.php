<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Policies;

use App\Domain\Enrollment\Models\Student;
use App\Models\User;

/**
 * Authorization for student records.
 *
 * Purely permission-based. There is no rank check and no ownership rule: a
 * student record carries no authorization weight, so nothing here can be an
 * escalation path the way a user account is.
 *
 * The grants are seeded by RolePermissionSeeder and the shape of them is a
 * decision the centre confirmed, not an accident:
 *
 *   - super_admin, admin: the full set.
 *   - staff:              view_any_student and view_student, and nothing else.
 *                         Front-desk staff answer questions about any student
 *                         who walks in, so the read is deliberately unscoped —
 *                         it is NOT limited to the batches they teach. They may
 *                         not create, edit or delete a record.
 *   - student:            nothing. The portal (phase 3) is a separate panel
 *                         with a separate guard, and this policy governs the
 *                         staff dashboard only.
 *
 * There is no deleteAny(): StudentResource registers no bulk actions. Filament
 * authorizes a bulk action once against the *Any method and never consults the
 * per-record one, so leaving it undefined makes any bulk delete added later
 * fail closed until someone decides the rule on purpose. See
 * docs/ENGINEERING.md, "Bulk actions cannot be authorized per record".
 */
class StudentPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('view_any_student');
    }

    public function view(User $actor, Student $student): bool
    {
        return $actor->can('view_student');
    }

    public function create(User $actor): bool
    {
        return $actor->can('create_student');
    }

    public function update(User $actor, Student $student): bool
    {
        return $actor->can('update_student');
    }

    public function delete(User $actor, Student $student): bool
    {
        return $actor->can('delete_student');
    }
}
