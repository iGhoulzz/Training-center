<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Students, courses, batches and enrolments (P1-T14)
|--------------------------------------------------------------------------
|
| The first three entries predate this task: P1-T11 added them because two
| visible values were being assembled by string interpolation, and both the
| SEPARATOR and the ORDER of the parts are localisable — Arabic reverses them.
| They are the pattern the rest of this file follows: named placeholders, so a
| translator can reorder without touching code.
|
| Keys are flat and named for what they label, not for where they appear. A
| label used in three resources is one key; the day the wording changes, it
| changes once.
*/

return [
    // Composite formats (P1-T11). Placeholders are named, never positional.
    'student_option_label' => ':code — :name',
    'enrolment_load_value' => ':active / :capacity',
    'no_capacity_limit_short' => '—',

    // Resource and relation labels.
    'student' => 'Student',
    'students' => 'Students',
    'course' => 'Course',
    'courses' => 'Courses',
    'batch' => 'Batch',
    'batches' => 'Batches',
    'instructor' => 'Instructor',
    'instructors' => 'Instructors',
    'enrollments' => 'Enrolments',

    // Student fields.
    'student_code' => 'Student code',
    'first_name' => 'First name',
    'last_name' => 'Last name',
    'full_name' => 'Name',
    'national_id' => 'National ID',
    'date_of_birth' => 'Date of birth',
    'gender' => 'Gender',
    'gender_male' => 'Male',
    'gender_female' => 'Female',
    'phone' => 'Phone',
    'email' => 'Email',
    'address' => 'Address',
    'notes' => 'Notes',
    'status' => 'Status',

    // Course fields.
    'course_code' => 'Course code',
    'course_name' => 'Course name',
    'name_en' => 'Name (English)',
    'name_ar' => 'Name (Arabic)',
    'description_en' => 'Description (English)',
    'description_ar' => 'Description (Arabic)',
    'total_hours' => 'Total hours',
    'is_active' => 'Active',

    // Batch fields.
    'batch_code' => 'Batch code',
    'start_date' => 'Start date',
    'end_date' => 'End date',
    'capacity' => 'Capacity',
    'enrolment_load' => 'Enrolled',
    'hour_allocation' => 'Hours allocated',
    'assigned_hours' => 'Assigned hours',
    'instructor_employment_state' => 'Employment',
    'instructor_current' => 'Current',
    'instructor_departed' => 'Departed',

    // Enrolment fields.
    'enrolled_at' => 'Enrolled on',

    // Actions.
    'create_student' => 'New student',
    'edit_student' => 'Edit student',
    'create_course' => 'New course',
    'edit_course' => 'Edit course',
    'create_batch' => 'New batch',
    'edit_batch' => 'Edit batch',
    'enroll_student' => 'Enrol student',
    'withdraw' => 'Withdraw',
    'delete_enrollment' => 'Delete enrolment',
    'assign_instructor' => 'Assign instructor',
    'remove_instructor' => 'Remove instructor',
    'edit_assigned_hours' => 'Edit assigned hours',

    // Empty-state placeholders. Shown instead of a blank cell, so that "not
    // recorded" stays distinguishable from "the page failed to load it".
    'no_date' => 'Not set',
    'no_phone' => 'No phone',
    'no_job_title' => 'No job title',
    'inherits_from_course' => 'Inherited from the course',

    // Helper text.
    // Zero, not empty. The field is required and the column is NOT NULL — see
    // BatchResource, where "leave it blank" was removed precisely because an
    // empty box reached MySQL as NULL and surfaced as a raw query error.
    'capacity_hint' => 'Enter 0 for no limit. Enrolling past a limit is allowed and warns.',
    'total_hours_course_hint' => 'The default teaching hours for every batch of this course.',
    'total_hours_batch_hint' => 'Leave empty to use the course total. A value here applies to this batch only.',
    'is_active_course_hint' => 'Inactive courses stay on record but accept no new batches.',
    'assigned_hours_hint' => 'The hours this instructor teaches on this batch.',
    'hour_mismatch_hint' => 'Assigned instructor hours do not match the batch total.',
    'delete_enrollment_warning' => 'This removes the enrolment record entirely. To keep the history, withdraw the student instead.',
    'instructor_departed_hint' => 'This instructor has left the centre. The assignment is kept so past batches stay accurate.',
    'batch_in_use_hint' => 'Remove the enrolments and the assigned instructors first.',
    'course_in_use_hint' => 'Delete the batches first, or deactivate the course to stop new ones.',

    // Refusals. These reach the user as notification titles and as exception
    // messages, so they are whole sentences rather than fragments.
    'over_capacity_warning' => 'This batch is now over capacity.',
    'duplicate_enrollment' => 'This student is already enrolled in this batch.',
    'student_not_enrollable' => 'This student record has been deleted and cannot take new enrolments.',
    // Two operations, not all of them. Spec section 6 gates new enrolments and
    // instructor changes on a completed or cancelled batch; the batch's own
    // details stay editable, and saying otherwise sends a user looking for a
    // permission problem that does not exist.
    'batch_closed' => 'Completed and cancelled batches take no new enrolments and no instructor changes.',
    // Both restricting foreign keys, because either is a correct reason to
    // refuse. Naming only enrolments sends the user to an empty list.
    'batch_in_use' => 'This batch still has enrolments or assigned instructors and cannot be deleted.',
    'course_in_use' => 'This course has batches and cannot be deleted.',
    'instructor_not_eligible' => 'This person is not an eligible instructor.',
    'instructor_change_denied' => 'You are not allowed to change instructors on this batch.',
    'enrollment_change_denied' => 'You are not allowed to change this enrolment.',
    // Withdrawing an already-withdrawn enrolment is a no-op, not a refusal.
    // This is the completed case only.
    'enrollment_not_withdrawable' => 'Only an active enrolment can be withdrawn.',
    'enrollment_batch_changed' => 'This enrolment moved to another batch. Reload and try again.',

    /*
     * Enum labels, reached by interpolation — BatchStatus::label() builds
     * "enrollment.batch_status.{$this->value}" — so a missing case is invisible
     * to any scan of literal __() calls. LocalizationTest walks the enum cases
     * themselves for exactly that reason.
     */
    'student_status' => [
        'prospective' => 'Prospective',
        'active' => 'Active',
        'graduated' => 'Graduated',
        'inactive' => 'Inactive',
    ],

    'batch_status' => [
        'planned' => 'Planned',
        'active' => 'Active',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ],

    'enrollment_status' => [
        'active' => 'Active',
        'completed' => 'Completed',
        'withdrawn' => 'Withdrawn',
    ],
];
