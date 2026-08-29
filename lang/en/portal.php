<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Student portal
|--------------------------------------------------------------------------
|
| T1 ships the panel itself; its four pages arrive in T7, which extends this
| file. The brand is here rather than hardcoded in StudentPanelProvider because
| it is the one user-facing string the panel renders on its own — the login page
| and the password form draw everything else from auth.php.
|
| lang/ar/portal.php ships empty. Arabic arrives in phase 4; the structure is
| enforced from commit one, and LocalizationTest derives its Arabic-empty dataset
| from this directory.
*/

return [
    'brand' => 'Student Portal',
];
