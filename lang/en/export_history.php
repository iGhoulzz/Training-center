<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The export history (phase 4, P4-T08)
|--------------------------------------------------------------------------
|
| A page listing the viewer's own queued spreadsheet report exports. The file is
| named with an underscore, not a hyphen: LocalizationTest's key scan matches
| `[a-z_]+` for the group and cannot see a hyphenated catalogue.
|
| A report's NAME is not here, on purpose: a row is titled by the report it
| exported, which is `reports.pages.<kind>.title`, so the history reads exactly as
| the report page does and cannot drift from it. `other_export` is only for an
| export that is not one of the report exporters.
|
| The four statuses are the page's whole vocabulary. "No longer available" does
| not name a retention period: how long the files are kept is the purge's to say,
| and a number copied here would be wrong the day that changed.
*/

return [
    'title' => 'Export history',
    'navigation_label' => 'Export history',
    'subheading' => 'This page lists your spreadsheet report exports; PDF reports arrive as notifications and are not listed.',
    'empty' => 'You have not exported any spreadsheet reports yet.',
    'other_export' => 'Other export',

    'columns' => [
        'report' => 'Report',
        'requested_at' => 'Requested',
        'rows' => 'Rows',
        'status' => 'Status',
    ],

    'statuses' => [
        'queued' => 'Queued',
        'ready' => 'Ready',
        'failed' => 'Failed',
        'unavailable' => 'No longer available',
    ],

    'actions' => [
        'download' => 'Download',
    ],
];
