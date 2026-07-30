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
     * Bulk delete is closed outright (P1-T06b), mirroring
     * StaffProfilePolicy::deleteAny().
     *
     * Deleting a certificate is no longer a plain row removal:
     * DeleteStaffCertificateAction writes a pending_file_deletions receipt in the
     * same transaction as the delete and schedules the bytes for removal. Filament
     * authorizes a bulk delete once against this method and never runs the
     * per-record path, so a bulk delete would drop the rows and orphan every
     * certificate file on disk. Returning false unconditionally keeps that
     * impossible even if a bulk action were mistakenly registered; the
     * certificates relation manager registers none.
     */
    public function deleteAny(User $actor): bool
    {
        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Dormant Filament abilities (P1-T15, security finding 1)
    |--------------------------------------------------------------------------
    |
    | THESE ARE NOT REDUNDANT AND MUST NOT BE DELETED AS DEAD CODE.
    |
    | Filament and Laravel disagree about a MISSING policy method. Laravel's Gate
    | returns false (Illuminate/Auth/Access/Gate.php: "if (! is_callable(...)) {
    | return false; }"). Filament's get_authorization_response() consults the
    | Gate only when method_exists($policy, $action); otherwise, with strict
    | authorization off — the default, and this panel never enables it — and no
    | Gate::before callback registered, it falls through to Response::allow().
    | See vendor/filament/filament/src/helpers.php.
    |
    | So an ability this policy simply does not mention is DENIED everywhere a
    | test would look and ALLOWED everywhere a user would click. Writing them out
    | is what makes the answer real.
    |
    | They return false because these operations do not exist in phase 1, not
    | because of who is asking: no restore, force-delete or bulk control is
    | rendered anywhere. The danger is the next person to add the standard
    | Filament soft-delete idiom to a resource and inherit an open door.
    |
    | The *Any abilities are refused for a second reason as well: Filament
    | authorizes a bulk action ONCE against them and never consults the
    | per-record rule, so any protection expressed per record would be skipped.
    */

    public function restore(User $authUser, StaffCertificate $record): bool
    {
        return false;
    }

    public function restoreAny(User $authUser): bool
    {
        return false;
    }

    public function forceDelete(User $authUser, StaffCertificate $record): bool
    {
        return false;
    }

    public function forceDeleteAny(User $authUser): bool
    {
        return false;
    }

    public function replicate(User $authUser, StaffCertificate $record): bool
    {
        return false;
    }

    public function reorder(User $authUser): bool
    {
        return false;
    }
}
