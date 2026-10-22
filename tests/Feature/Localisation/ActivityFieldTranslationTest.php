<?php

declare(strict_types=1);

use App\Domain\Staff\Filament\Resources\ActivityResource;
use App\Domain\Staff\Support\RecordsActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

it('names every audited attribute in both catalogues from the model declarations', function () {
    $fields = [];
    $models = 0;
    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $relative = substr($file->getPathname(), strlen(app_path()) + 1, -4);
        $class = 'App\\'.str_replace(['/', '\\'], '\\', $relative);
        if (! class_exists($class) || ! in_array(RecordsActivity::class, class_uses_recursive($class), true)) {
            continue;
        }
        $models++;
        $fields = array_merge($fields, (new $class)->auditedAttributes());
    }
    expect($models)->toBeGreaterThan(15)->and($fields)->not->toBeEmpty();
    foreach (['en', 'ar'] as $locale) {
        $catalogue = require lang_path($locale.'/activity.php');
        expect(array_diff(array_unique($fields), array_keys($catalogue['field'] ?? [])))->toBe([]);
    }
});

it('uses an Arabic catalogue sentinel for each property and change shape', function (string $shape) {
    app()->setLocale('ar');
    Lang::addLines(['activity.field.name' => 'علامة عربية تجريبية'], 'ar');
    $entry = new Activity;
    $entry->properties = collect(['name' => 'New value', 'unknown_future_field' => 'Fallback value']);
    $entry->attribute_changes = collect([
        'attributes' => ['name' => 'New value', 'unknown_future_field' => 'Fallback value'],
        'old' => $shape === 'changed' ? ['name' => 'Old value'] : [],
    ]);
    $rendered = $shape === 'property' ? ActivityResource::describeProperties($entry) : ActivityResource::describeChanges($entry);
    expect($rendered)->toContain('علامة عربية تجريبية', 'New value', 'unknown_future_field', 'Fallback value')
        ->not->toContain('activity.field.unknown_future_field');
    if ($shape === 'changed') {
        expect($rendered)->toContain('Old value');
    }
})->with(['property', 'added', 'changed']);

it('keeps an unknown field readable in English as well', function () {
    app()->setLocale('en');
    $entry = new Activity;
    $entry->properties = collect(['future_identifier' => 'Value']);
    $entry->attribute_changes = collect(['attributes' => ['future_identifier' => 'Value']]);
    expect(ActivityResource::describeProperties($entry))->toBe('future_identifier: Value')
        ->and(ActivityResource::describeChanges($entry))->toBe('future_identifier: Value');
});
