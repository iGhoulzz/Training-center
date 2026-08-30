<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Models;

use App\Domain\Enrollment\Enums\CertificateStatus;
use App\Domain\Staff\Support\RecordsActivity;
use App\Models\User;
use Database\Factories\StudentCertificateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One physical certificate the centre issued (design section 6).
 *
 * A RECORD OF WHAT WAS HANDED OVER, NOT A DOCUMENT STORE
 * ------------------------------------------------------
 * There is no PDF, template, image, disk or path here, and none is coming:
 * certificate design and printing are outside the whole project (system design
 * section 12). This row says a certificate with these words on it exists.
 *
 * THE SNAPSHOT COLUMNS DO NOT FOLLOW LATER CORRECTIONS
 * -----------------------------------------------------
 * `student_name`, `course_name` and `completed_on` are frozen at issuance to
 * match the paper the student was handed. Renaming a course afterwards must not
 * silently rewrite documents already in people's hands — the same reasoning
 * `charges.list_price` carries for a bill, and the reason the public verifier
 * can quote these fields years later and still be describing the physical
 * object.
 *
 * DELIBERATELY THIN
 * -----------------
 * No business logic, per docs/ENGINEERING.md. The three transitions — issue,
 * replace, revoke — are T5's Actions, each taking a lock and deciding; this
 * model holds casts, relationships and the audit contract, and nothing that
 * decides anything.
 *
 * NOT SOFT-DELETED, AND NOT DELETABLE
 * -----------------------------------
 * An issued row is never destroyed, so there is no `deleted_at` to sweep and no
 * delete path for any role: `StudentCertificatePolicy` refuses `delete`
 * unconditionally, and `student_certificates.enrollment_id` restricts on delete
 * so the register cannot be emptied by tidying up an enrolment either.
 */
#[Fillable([
    'enrollment_id',
    'reference_number',
    'student_name',
    'course_name',
    'completed_on',
    'issued_at',
    'issued_by',
    'status',
    'replaces_certificate_id',
    'revoked_at',
    'revoked_by',
    'revocation_reason',
])]
class StudentCertificate extends Model
{
    /** @use HasFactory<StudentCertificateFactory> */
    use HasFactory;

    use RecordsActivity;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'completed_on' => 'date',
            'issued_at' => 'datetime',
            'revoked_at' => 'datetime',
            'status' => CertificateStatus::class,
        ];
    }

    /**
     * The enrolment this certificate was issued against.
     *
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /**
     * Who issued it.
     *
     * @return BelongsTo<User, $this>
     */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * Who revoked it, when one was revoked.
     *
     * @return BelongsTo<User, $this>
     */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /**
     * The certificate this one superseded, if it was issued as a replacement.
     *
     * The pointer lives on the NEW row and points backwards — design section
     * 6.4. A forward pointer on the old row would have to be written after the
     * new row exists, which is an ordering hazard for no gain.
     *
     * @return BelongsTo<self, $this>
     */
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_certificate_id');
    }

    /** See StaffProfile::newFactory() for why this is stated rather than guessed. */
    protected static function newFactory(): StudentCertificateFactory
    {
        return StudentCertificateFactory::new();
    }

    /**
     * The account columns worth an audit diff.
     *
     * `reference_number` is ABSENT ON PURPOSE, following the rule phase 2
     * established for `ENR-`/`CHG-`/`RCT-`: a reference is a deterministic
     * function of the row the log already identifies, so the audit trail can
     * still name the document without the value being duplicated into it.
     *
     * The snapshot columns ARE audited. They never legitimately change after
     * issuance, so a diff on one of them is precisely the event somebody would
     * want to find.
     *
     * @return array<int, string>
     */
    public function auditedAttributes(): array
    {
        return [
            'enrollment_id',
            'student_name',
            'course_name',
            'completed_on',
            'issued_at',
            'issued_by',
            'status',
            'replaces_certificate_id',
            'revoked_at',
            'revoked_by',
            'revocation_reason',
        ];
    }
}
