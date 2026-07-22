<?php

declare(strict_types=1);

namespace App\Domain\Staff\Policies;

use App\Domain\Staff\Models\StaffCertificate;
use App\Models\User;

/**
 * Authorization for uploaded staff credentials.
 *
 * Certificates get their own permissions rather than inheriting the profile's,
 * because they are a strictly more sensitive object: the scanned document
 * carries a full name, a national ID number, and a date of birth, where the
 * profile carries a job title. Separate permissions let a later role see who
 * teaches what without also being handed everyone's identity documents.
 *
 * view() is the gate the download route must call. A file on a private disk is
 * only as private as the check in front of it, and that check belongs here —
 * per request, per record, never once at upload time.
 *
 * The permissions are seeded by RolePermissionSeeder: super_admin and admin
 * hold the full set, staff and student hold none of it.
 */
class StaffCertificatePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('view_any_staff_certificate');
    }

    public function view(User $actor, StaffCertificate $certificate): bool
    {
        return $actor->can('view_staff_certificate');
    }

    public function create(User $actor): bool
    {
        return $actor->can('create_staff_certificate');
    }

    public function update(User $actor, StaffCertificate $certificate): bool
    {
        return $actor->can('update_staff_certificate');
    }

    public function delete(User $actor, StaffCertificate $certificate): bool
    {
        return $actor->can('delete_staff_certificate');
    }

    /**
     * See StaffProfilePolicy::deleteAny() for why a *Any method is answered
     * explicitly rather than left to fall through.
     */
    public function deleteAny(User $actor): bool
    {
        return $actor->can('delete_staff_certificate');
    }
}
