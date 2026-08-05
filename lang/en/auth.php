<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Authentication (P1-T14)
|--------------------------------------------------------------------------
|
| This file MERGES with the framework's own lang/en/auth.php rather than
| replacing it. Laravel's FileLoader reduces over both registered paths with
| array_replace_recursive, framework first, so 'failed', 'password' and
| 'throttle' still resolve even though they are absent here.
|
| That is worth knowing before anyone "completes" this file by pasting the
| framework's keys in: duplicating them means the login failure message stops
| tracking the framework and starts drifting silently.
*/

return [
    'current_password' => 'Current password',
    'new_password' => 'New password',
    'confirm_password' => 'Confirm password',
    'update_password' => 'Update password',
    'password_updated' => 'Password updated',

    /*
     * The password-change page's own logout control (G1-U3).
     *
     * That page renders no panel chrome, so the topbar user menu — where the
     * panel's logout normally lives — is not there. Without a control of its own
     * a flagged account would have no way off the page but closing the browser.
     */
    'log_out' => 'Log out',
];
