<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Data;

/**
 * One programme, in the shape the public pages render directly (P4-T12).
 *
 * A CLOSED, NAMED PROJECTION, NEVER A MODEL. docs/ENGINEERING.md: "A public
 * surface renders a closed, named projection — never a model." This is the
 * programmes' version of CertificateVerificationView, and it exists for the same
 * reason. PublicProgrammeCatalogue builds one by naming each property off a
 * `Course` row and the intake read for it, never by handing the row itself, or
 * its `toArray()`, to a view. A column added to `courses` in a later phase
 * (`default_price` is already one, and it must never be published for a course
 * with no intake) therefore cannot reach the public internet by default. Reaching
 * it takes a deliberate new property here AND a deliberate line in the catalogue
 * that fills it. PublicPagesTest pins this class's property list and checks that
 * no view on a public route is handed a model.
 *
 * WHAT IS HERE IS WHAT IS PUBLIC: the id (a programme's URL), the name and the
 * description in the reader's language, the hours, and, when an intake is
 * planned, its price and start date. Seats, capacity, enrolments, the course code
 * and the course's own default price are ABSENT ON PURPOSE.
 *
 * WHERE THE VALUES COME FROM. `price` and `startDate` come from the next planned
 * batch, whose price PricingService has already worked out, so this class never
 * calculates a price. `hours` are that batch's effective hours, or the course's
 * own `total_hours` when no intake is planned.
 *
 * A COURSE WITH NO PLANNED INTAKE HAS NO PRICE AND NO DATE. `price` and
 * `startDate` are null together, and a template that reads them tests for null,
 * so a blank price can never look like a real one: a price with no batch behind it
 * is a promise the centre has not made.
 */
final readonly class PublicProgramme
{
    /**
     * @param  string  $name  The course name in the reader's language.
     * @param  string  $description  The description in the reader's language, '' when it has none.
     * @param  int  $hours  The planned intake's hours, else the course's own total hours.
     * @param  string|null  $price  The planned intake's price as bare decimal digits ("1450.000"), null with no intake.
     * @param  string|null  $startDate  The planned intake's start date as Y-m-d, null with no intake.
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $description,
        public int $hours,
        public ?string $price,
        public ?string $startDate,
    ) {}
}
