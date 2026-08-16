<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources\PaymentResource\Pages;

use App\Domain\Finance\Filament\Resources\PaymentResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Payments, listed. Read-plus-one-action — see `PaymentResource`'s own
 * docblock.
 *
 * NO HEADER ACTIONS, AND NO CREATE PAGE TO LINK TO.
 * --------------------------------------------------
 * Unlike `ChargePolicy::create()`, `PaymentPolicy::create()` is a real
 * permission `admin` and `super_admin` both hold — but `getPages()` on
 * `PaymentResource` registers no create route for it to gate, because
 * design section 2 requires a payment to be recorded through the
 * collection flow (task 9), against a bill already open in front of the
 * operator, not by typing a row into this resource. `getHeaderActions()`
 * is not overridden for the same reason `ChargeResource`'s `ListCharges`
 * leaves it alone: `ListRecords` returns an empty array by default, and
 * writing `[]` here would read as "someone considered adding one".
 */
class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;
}
