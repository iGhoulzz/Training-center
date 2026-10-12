<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\StudentCertificate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function restyledVerifierDocument(string $html): DOMXPath
{
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument;
    $document->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($document);
}

it('keeps a labelled native reference form with CSRF and accessible guidance', function () {
    $response = $this->get(route('verify.certificates.form'))->assertSuccessful();
    $document = restyledVerifierDocument((string) $response->getContent());

    expect($document->query('//form')->length)->toBe(1);
    expect($document->evaluate('string(//form/@method)'))->toBe('POST');
    expect($document->evaluate('string(//form/@action)'))->toBe(route('verify.certificates.submit'));
    expect($document->evaluate('string(//form/input[@name="_token"]/@value)'))->not->toBeEmpty();
    expect($document->query('//form//input[@name="reference"][@required]')->length)->toBe(1);
    expect($document->query('//label[@for="reference"]')->length)->toBe(1);
    expect($document->evaluate('string(//input[@name="reference"]/@aria-describedby)'))->toBe('reference-help');
    expect(trim((string) $document->evaluate('string(//*[@id="reference-help"])')))->not->toBeEmpty();
    expect($document->query('//button')->length)->toBe(1);
    expect($document->query('//button[@type="submit"]')->length)->toBe(1);
    expect($document->query('//form[@novalidate]')->length)->toBe(0);
});

it('gives every verifier page a public-site link and one main heading', function (string $page) {
    $certificate = StudentCertificate::factory()->create();
    $url = match ($page) {
        'form' => route('verify.certificates.form'),
        'result' => route('verify.certificates.show', ['reference' => $certificate->reference_number]),
        'miss' => route('verify.certificates.show', ['reference' => 'not-a-reference']),
    };

    $response = $this->get($url);
    $document = restyledVerifierDocument((string) $response->getContent());

    expect($response->status())->toBe($page === 'miss' ? 404 : 200);
    expect($document->evaluate('string(//header//a/@href)'))->toBe(url('/'));
    expect(trim((string) $document->evaluate('string(//header//a)')))->not->toBeEmpty();
    expect($document->query('//main')->length)->toBe(1);
    expect($document->query('//main//h1')->length)->toBe(1);
})->with(['form', 'result', 'miss']);

it('renders each certificate state in an accessible result with only the closed projection', function (string $state, string $label, string $message) {
    $factory = StudentCertificate::factory();

    if ($state === 'revoked') {
        $factory = $factory->revoked('PRIVATE-REVOCATION-REASON');
    } elseif ($state === 'replaced') {
        $factory = $factory->replaced();
    }

    $certificate = $factory->create([
        'student_name' => 'Amina Zarrouk',
        'course_name' => 'Welding Level 1',
        'completed_on' => '2026-05-01',
        'issued_at' => '2026-05-04 09:00:00',
        ...($state === 'revoked' ? ['revoked_at' => '2026-06-02 22:30:00'] : []),
    ]);

    $response = $this->get(route('verify.certificates.show', ['reference' => $certificate->reference_number]))
        ->assertSuccessful()
        ->assertSee($message)
        ->assertDontSee('Contact hours')
        ->assertDontSee('PRIVATE-REVOCATION-REASON');
    $document = restyledVerifierDocument((string) $response->getContent());

    expect($document->query('//section[@aria-labelledby="result-heading"]//h2[@id="result-heading"]')->length)->toBe(1);
    expect($document->query('//dl/dt | //dl/div/dt')->length)->toBe(6);
    expect($document->query('//dl/dd | //dl/div/dd')->length)->toBe(6);

    $labels = array_map(fn (DOMNode $node): string => trim($node->textContent), iterator_to_array($document->query('//dl//dt')));
    expect($labels)->toBe([
        __('verify.field_status'), __('verify.field_student'), __('verify.field_course'),
        __('verify.field_completed_on'), __('verify.field_issued_at'), __('verify.field_confirmation'),
    ]);
    $values = array_map(fn (DOMNode $node): string => trim($node->textContent), iterator_to_array($document->query('//dl//dd')));
    expect($values)->toBe([
        $label, 'Amina Zarrouk', 'Welding Level 1',
        '2026-05-01', '2026-05-04 11:00', config('app.name'),
    ]);
    expect($document->evaluate('string(//main//a/@href)'))->toBe(route('verify.certificates.form'));
})->with([
    'valid' => ['valid', 'Valid', 'This certificate is valid.'],
    'replaced' => ['replaced', 'Replaced', 'This certificate has been superseded by a more recent certificate and is no longer current.'],
    'revoked' => ['revoked', 'Revoked', 'This certificate was revoked on 2026-06-03 and is no longer valid.'],
]);

it('keeps every miss byte-identical without an input, token or submitted value', function () {
    $responses = [
        $this->post(route('verify.certificates.submit'), []),
        $this->post(route('verify.certificates.submit'), ['reference' => '']),
        $this->post(route('verify.certificates.submit'), ['reference' => 'PRIVATE-MALFORMED-INPUT']),
        $this->get(route('verify.certificates.show', ['reference' => 'PRIVATE-MALFORMED-INPUT'])),
        $this->get(route('verify.certificates.show', ['reference' => 'TC-2099-ABCDEFGH'])),
    ];

    $expectedBody = $responses[0]->getContent();

    foreach ($responses as $response) {
        $response->assertNotFound()->assertDontSee('PRIVATE-MALFORMED-INPUT')->assertDontSee('TC-2099-ABCDEFGH');
        expect($response->getContent())->toBe($expectedBody)->not->toContain('_token');
        $document = restyledVerifierDocument((string) $response->getContent());
        expect($document->query('//form | //input')->length)->toBe(0);
        expect($document->query('//section[@aria-labelledby="result-heading"]//h2[@id="result-heading"]')->length)->toBe(1);
        expect($document->evaluate('string(//main//a/@href)'))->toBe(route('verify.certificates.form'));
    }
});
