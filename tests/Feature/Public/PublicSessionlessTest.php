<?php

declare(strict_types=1);

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Cookie;

uses(RefreshDatabase::class);

// Rendering a panel login page marks "a Livewire component rendered this request"
// in static state that outlives the test. Left set, Livewire injects its script
// into the next test's HTML page, which fails the verifier's no-script assertion
// in whichever file happens to run after this one. PulseAccessTest does the same.
afterEach(function (): void {
    Livewire::flushState();
});

/*
|--------------------------------------------------------------------------
| The public pages issue no cookie, and everything else still does (P4-T11)
|--------------------------------------------------------------------------
|
| "No session for an anonymous visitor" is the property that lets any layer in
| front of the application cache these pages, and it has two halves. The public
| group must set nothing. And the surfaces that DO need a session, a CSRF check
| or both (the verifier's form, both panels' login pages, the private-file
| routes) must be exactly as they were. Removing middleware from one group is
| one edit away from removing it from all of them, so the second half is tested
| with the same weight as the first.
*/

/**
 * The names of every cookie a response sets.
 *
 * @return array<int, string>
 */
function cookieNamesOn(TestResponse $response): array
{
    return array_map(fn (Cookie $cookie): string => $cookie->getName(), $response->headers->getCookies());
}

/**
 * The layout and every file under resources/views/public/, as absolute paths.
 *
 * @return array<int, string>
 */
function publicViewFilesForSessionScan(): array
{
    $files = [resource_path('views/layouts/public.blade.php')];

    foreach (File::allFiles(resource_path('views/public')) as $file) {
        $files[] = (string) $file->getRealPath();
    }

    return $files;
}

/**
 * The first construct in this source that needs a session, or null.
 *
 * The six the plan names (a form, the CSRF directive, a `csrf_` helper, and the
 * `session(`, `old(` and `auth(` calls), plus the shapes that reach the same
 * state another way: the auth and session facades, the `@auth` and `@guest`
 * directives, and the error bag, which only exists because a session middleware
 * put it there. A named function with samples, so it cannot quietly narrow.
 *
 * A BLADE COMMENT IS NOT SCANNED: it is removed before the template renders, so a
 * note explaining the rule may name a construct without being one. Anything after
 * the comment still is scanned.
 */
function firstSessionDependentConstruct(string $source): ?string
{
    $source = (string) preg_replace('/\{\{--.*?--\}\}/s', ' ', $source);

    $callable = '(?<![\w$>:])';

    $patterns = [
        '<form' => '/<form\b/i',
        '@csrf' => '/@csrf\b/',
        'csrf_' => '/csrf_/i',
        'session(' => '/'.$callable.'session\s*\(/',
        'old(' => '/'.$callable.'old\s*\(/',
        'auth(' => '/'.$callable.'auth\s*\(/',
        'Auth::/Session::' => '/\b(?:Auth|Session)::/',
        '@auth/@guest' => '/@(?:auth|guest)\b/',
        '@error/$errors' => '/@error\b|\$errors\b/',
    ];

    foreach ($patterns as $label => $pattern) {
        if (preg_match($pattern, $source) === 1) {
            return $label;
        }
    }

    return null;
}

/**
 * Make the CSRF check real for the rest of this test.
 *
 * THE 419 TRAP. PreventRequestForgery returns early when it is "running unit
 * tests", and also when the request carries `Sec-Fetch-Site: same-origin`. Under
 * PHPUnit it therefore waves every POST through, and a test written to expect the
 * refusal would never see one. This binds an anonymous subclass that reports it
 * is NOT running tests; the requests below send no Sec-Fetch-Site header. The
 * middleware is resolved from the container on every request, so the binding
 * reaches it.
 */
function enforceCsrfCheck(): void
{
    app()->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app->make(Encrypter::class)) extends PreventRequestForgery
    {
        protected function runningUnitTests(): bool
        {
            return false;
        }
    });
}

/** A token the tests put in the session and send back. */
const KNOWN_CSRF_TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

it('serves the home page with no Set-Cookie header at all', function () {
    $response = $this->get('/')->assertOk();

    // Both ways of asking, because the cookie bag and the header list are two
    // views of one response, and a regression could show in either.
    expect($response->headers->getCookies())->toBe([])
        ->and($response->headers->all('set-cookie'))->toBe([])
        ->and($response->headers->has('Set-Cookie'))->toBeFalse();

    $response->assertHeaderMissing('Set-Cookie');
});

it('renders the home page with no form and no CSRF token', function () {
    $body = strtolower((string) $this->get('/')->assertOk()->getContent());

    expect($body)->not->toContain('<form')
        ->and($body)->not->toContain('_token')
        ->and($body)->not->toContain('csrf');
});

it('finds the files the source scan reads', function () {
    $names = array_map('basename', publicViewFilesForSessionScan());

    // The control: a scan over an empty list passes by finding nothing.
    expect($names)->toContain('public.blade.php')
        ->toContain('header.blade.php')
        ->toContain('navigation.blade.php')
        ->toContain('footer.blade.php')
        ->toContain('home.blade.php');
});

it('has no session-dependent construct in the public layout or views', function () {
    $offenders = [];

    foreach (publicViewFilesForSessionScan() as $path) {
        if (($match = firstSessionDependentConstruct((string) file_get_contents($path))) !== null) {
            $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path).' -> '.$match;
        }
    }

    expect($offenders)->toBeEmpty(
        "The public pages are sessionless, so none of these may appear in them:\n  ".implode("\n  ", $offenders),
    );
});

it('flags every construct that needs a session', function (string $sample) {
    expect(firstSessionDependentConstruct($sample))->not->toBeNull();
})->with([
    '<form method="post">',
    '<FORM action="/x">',
    '@csrf',
    '{{ csrf_token() }}',
    '<meta name="x" content="{{ csrf_field() }}">',
    "{{ session('status') }}",
    "{{ session ('status') }}",
    "{{ old('name') }}",
    '{{ auth()->user() }}',
    '{{ Auth::user() }}',
    "{{ Session::get('x') }}",
    '@auth x @endauth',
    '@guest x @endguest',
    '@error(\'x\') y @enderror',
    '{{ $errors->first() }}',
]);

it('leaves ordinary markup and similar words alone', function (string $sample) {
    expect(firstSessionDependentConstruct($sample))->toBeNull();
})->with([
    "<a href=\"{{ PublicRoute::url('public.home') }}\">{{ __('public.brand') }}</a>",
    '<details><summary>x</summary></details>',
    // Words that merely end in the names above.
    "{{ \$threshold(1) }} {{ bold('x') }} {{ \$request->session() }}",
    '<formatted>x</formatted>',
    '<p>An authority on old things</p>',
    // A Blade comment is removed before rendering, so it may name a construct.
    '{{-- No @csrf and no session() on these pages. --}}',
]);

it('still scans what follows a Blade comment', function () {
    expect(firstSessionDependentConstruct("{{-- a note --}}{{ session('x') }}"))->toBe('session(');
});

it('issues the session cookie on the verifier form and refuses a POST without a CSRF token', function () {
    enforceCsrfCheck();

    $form = $this->get(route('verify.certificates.form'))->assertOk();

    expect(cookieNamesOn($form))->toContain(config('session.cookie'));

    $this->post(route('verify.certificates.submit'), ['reference' => 'CERT-2026-0087'])
        ->assertStatus(419);
});

it('does not refuse the same verifier POST once it carries a valid token', function () {
    // Proves the binding in enforceCsrfCheck() is live. If it were not, the 419
    // above would be vacuous in the other direction: the middleware would be
    // waving every POST through, and a token would change nothing.
    enforceCsrfCheck();

    $response = $this->withSession(['_token' => KNOWN_CSRF_TOKEN])
        ->post(route('verify.certificates.submit'), ['_token' => KNOWN_CSRF_TOKEN, 'reference' => 'CERT-2026-0087']);

    expect($response->getStatusCode())->not->toBe(419);
});

it('issues the session cookie on each panel login page', function (string $routeName) {
    $response = $this->get(route($routeName))->assertOk();

    expect(cookieNamesOn($response))->toContain(config('session.cookie'));
})->with([
    'admin' => 'filament.admin.auth.login',
    'student' => 'filament.student.auth.login',
]);

it('refuses a Livewire update without a CSRF token, and accepts the same one with it', function () {
    enforceCsrfCheck();

    $this->postJson(Livewire::getUpdateUri(), ['components' => []], ['X-Livewire' => 'true'])
        ->assertStatus(419);

    $response = $this->withSession(['_token' => KNOWN_CSRF_TOKEN])
        ->postJson(
            Livewire::getUpdateUri(),
            ['components' => []],
            ['X-Livewire' => 'true', 'X-CSRF-TOKEN' => KNOWN_CSRF_TOKEN],
        );

    expect($response->getStatusCode())->not->toBe(419);
});

it('still starts a session for a guest on each private-file route, whatever the status', function (string $routeName, array $parameters) {
    // These three routes are GET-only, so there is no POST to refuse and the CSRF
    // check is moot here. What this guards is the other half of the property: that
    // taking the session off the public group did not take it off this one. A guest
    // is refused or finds nothing, and the response still carries the cookie.
    $response = $this->get(route($routeName, $parameters));

    expect(cookieNamesOn($response))->toContain(config('session.cookie'));
})->with([
    'staff certificate' => ['staff.certificates.download', ['certificate' => 1]],
    'staff photo' => ['staff.profiles.photo', ['profile' => 1]],
    'receipt' => ['finance.receipts.download', ['payment' => 1]],
]);
