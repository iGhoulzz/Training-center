<?php

declare(strict_types=1);

use App\Support\PublicContact;

/*
 * PublicContact is the ONLY place the public site's contact rules live: the
 * header, the home page and the contact page all read it. Every expectation
 * below is written by hand from the input, never computed by the code under test.
 *
 * It runs without the application: fromValues() is the whole rule and
 * fromConfig() only reads three config keys into it (PublicPagesTest checks that
 * and the rendered links).
 */

it('trims the email and leaves it otherwise as written', function () {
    expect(PublicContact::fromValues("  desk@example.test \n", '', '')->email)->toBe('desk@example.test')
        ->and(PublicContact::fromValues('Desk@Example.Test', '', '')->email)->toBe('Desk@Example.Test');
});

it('keeps the digits and a leading plus in the tel target, and the written text for display', function (string $written, string $href) {
    $contact = PublicContact::fromValues('', $written, '');

    expect($contact->phone)->toBe(trim($written))
        ->and($contact->telephoneHref)->toBe($href);
})->with([
    'plus and spaces' => ['+218 91 234 5678', 'tel:+218912345678'],
    'plus and dashes' => ['+218-928421304', 'tel:+218928421304'],
    'brackets' => ['+1 (234) 567-890', 'tel:+1234567890'],
    'no plus' => ['0912 345 678', 'tel:0912345678'],
    'only digits' => ['218912345678', 'tel:218912345678'],
    'plus after trimming' => ['   +218 91   ', 'tel:+21891'],
    'a plus that is not leading goes' => ['218+91', 'tel:21891'],
    'letters and dots go' => ['(+218) 91.234 ext', 'tel:21891234'],
]);

it('gives an empty tel target when there is no phone', function (string $written) {
    $contact = PublicContact::fromValues('', $written, '');

    expect($contact->phone)->toBe('')
        ->and($contact->telephoneHref)->toBe('');
})->with(['empty' => [''], 'blank' => ['   '], 'whitespace' => ["\t\n "]]);

it('lets a plain http or https map link through, trimmed', function (string $written, string $expected) {
    expect(PublicContact::fromValues('', '', $written)->mapUrl)->toBe($expected);
})->with([
    'https' => ['https://maps.app.goo.gl/nrs5mzbxjh9A7wci8', 'https://maps.app.goo.gl/nrs5mzbxjh9A7wci8'],
    'http' => ['http://maps.example.test/x?q=1', 'http://maps.example.test/x?q=1'],
    'trimmed' => ["  https://maps.example.test/x \n", 'https://maps.example.test/x'],
    'upper case scheme' => ['HTTPS://maps.example.test/x', 'HTTPS://maps.example.test/x'],
]);

it('gives an empty map link for a blank value or anything that is not a web link', function (string $written) {
    expect(PublicContact::fromValues('', '', $written)->mapUrl)->toBe('');
})->with([
    'empty' => [''],
    'blank' => ['   '],
    'javascript' => ['javascript:alert(1)'],
    'javascript with a web look' => ['javascript://maps.example.test/%0Aalert(1)'],
    'data' => ['data:text/html;base64,PHNjcmlwdD4='],
    'ftp' => ['ftp://maps.example.test/x'],
    'protocol relative' => ['//maps.example.test/x'],
    'a bare path' => ['/maps/place'],
    'no scheme' => ['maps.example.test/x'],
    'a web link that does not start the value' => ['see https://maps.example.test/x'],
]);

it('holds nothing when nothing is configured', function () {
    $contact = PublicContact::fromValues('', '', '');

    expect($contact->email)->toBe('')
        ->and($contact->phone)->toBe('')
        ->and($contact->telephoneHref)->toBe('')
        ->and($contact->mapUrl)->toBe('');
});
