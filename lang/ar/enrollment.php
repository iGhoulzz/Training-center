<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Arabic — deliberately empty until phase 4
|--------------------------------------------------------------------------
|
| Every key in lang/en/enrollment.php resolves through this file to its English
| value: Laravel's translator tries the active locale, then app.fallback_locale.
| An empty array is therefore a working state, not a broken one.
|
| It is empty ON PURPOSE. Seeding it with the English strings — the obvious
| shortcut — would make an untranslated panel indistinguishable from a
| translated one: nothing would render as missing, and no reviewer, screenshot
| or test could tell which strings a translator had actually reached. Absent
| means untranslated, and stays visible as such.
|
| Phase 4 fills this in. Add keys only with real Arabic; a partially filled file
| is fine, because the fallback covers whatever is not here yet.
*/

return [
];
