<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Billing — enrolling and the bill it raises (P2-T03)
|--------------------------------------------------------------------------
|
| One translation file per task, per design section 12: nine tasks editing a
| single finance.php is a guaranteed merge conflict, and the split costs
| nothing. The Arabic counterpart ships empty until phase 4, and
| LocalizationTest derives its Arabic-empty check from the files present here,
| so this catalogue is covered without registering it anywhere.
*/

return [
    /*
     * Why an enrolment could not be deleted. Each names a fact somebody
     * produced about money — see ChargeAlreadyCommittedException, which passes
     * the reason as a key rather than a sentence so these stay separable.
     */
    'charge_committed_allocated' => 'This enrolment cannot be deleted: a payment has already been recorded against its bill.',
    'charge_committed_adjusted' => 'This enrolment cannot be deleted: its bill was corrected, and that correction is part of the record.',
    'charge_committed_written_off' => 'This enrolment cannot be deleted: its bill has been written off.',
];
