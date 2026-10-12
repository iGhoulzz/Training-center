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
 * The layout, every file under resources/views/public/ and every public
 * component under resources/views/components/public/, as absolute paths.
 *
 * The components are included because a component renders inside a public page:
 * a form or a session call in one is on the page as surely as in the page's own
 * file.
 *
 * @return array<int, string>
 */
function publicViewFilesForSessionScan(): array
{
    $files = [resource_path('views/layouts/public.blade.php')];

    foreach ([resource_path('views/public'), resource_path('views/components/public')] as $directory) {
        foreach (File::allFiles($directory) as $file) {
            $files[] = (string) $file->getRealPath();
        }
    }

    return $files;
}

/**
 * The opening tag of the first form in this source that is not a read-only GET
 * form, or null (P4-T13).
 *
 * A form is allowed only when it carries `method="get"`, in either quote style and
 * any case, because a GET form writes its fields into the URL and needs neither a
 * session nor a CSRF token (docs/ENGINEERING.md, "Frontend (phase 4)"). EVERY
 * OTHER FORM FAILS, a form with no method attribute included: with none the
 * browser defaults to GET, but the rule stays explicit so a form's method is
 * always written down where a reviewer can see it. An unquoted `method=get` fails
 * for the same reason.
 *
 * The attribute is read out of the tag rather than searched for in the source, so
 * `method="get"` inside another attribute's value does not count (nor does a
 * `data-method`: the name must be exactly `method`), and a Blade echo with a `>`
 * in it (`$a->b`) cannot end the tag early. A tag that never closes is read to
 * the end of the source, so it cannot hide.
 *
 * A BLADE ECHO CAN HIDE A FORM OR AN ATTRIBUTE, SO THE TAG MAY HOLD ONE IN ONE
 * PLACE ONLY. Each echo is first swapped for a marker byte (`\x01` for `{{ }}`,
 * `\x02` for `{!! !!}`), never blanked to a letter, which would let
 * `<form{{ "" }} method="post">` read as a tag named `formx`. Then:
 *
 *   - a source with more `<form` in it than its marked copy has holds a form that
 *     is written inside an echo (`{!! '<form method="post">' !!}`), and is refused;
 *   - a tag holding an echo is refused, because an attribute bag
 *     (`<form {{ $attributes }} method="get">`) can carry a `method="post"` that a
 *     browser reads FIRST. The one exception is an escaped `{{ }}` inside the
 *     `action="..."` value: the search form's only dynamic part is its URL, and an
 *     escaped echo cannot close the quotes around it. A `{!! !!}` is refused
 *     there too, since it can;
 *   - a tag holding a Blade directive (`@`) or another `<` is refused for the same
 *     reason: either can write attributes the scan cannot see.
 *
 * A refused tag is returned with each echo shown as `{{ }}` or `{!! !!}`.
 * The caller strips Blade comments first, as firstSessionDependentConstruct() does.
 */
function firstNonGetForm(string $source): ?string
{
    $marked = (string) preg_replace(['/\{\{.*?\}\}/s', '/\{!!.*?!!\}/s'], ["\x01", "\x02"], $source);

    if (preg_match_all('/<form\b/i', $source) > preg_match_all('/<form\b/i', $marked)) {
        return '<form (hidden in a Blade echo)';
    }

    preg_match_all('/<form\b[^>]*(?:>|$)/i', $marked, $tags);

    foreach ($tags[0] as $tag) {
        $shown = str_replace(["\x01", "\x02"], ['{{ }}', '{!! !!}'], $tag);

        // Only the first `action` is the one a browser reads, so only its echo is let through.
        $outsideAction = (string) preg_replace('/(?<![\w-])action\s*=\s*(?:"[^"]*"|\'[^\']*\')/i', '', $tag, 1);

        if (
            str_contains(substr($tag, 1), '<')
            || str_contains($tag, '@')
            || str_contains($tag, "\x02")
            || str_contains($outsideAction, "\x01")
        ) {
            return $shown;
        }

        preg_match_all('/([^\s"\'<>\/=]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $tag, $attributes, PREG_SET_ORDER);

        $method = null;

        foreach ($attributes as $attribute) {
            if (strtolower($attribute[1]) === 'method') {
                // The first `method` attribute is the one a browser reads.
                $method = strtolower($attribute[2] !== '' ? $attribute[2] : ($attribute[3] ?? ''));

                break;
            }
        }

        if ($method !== 'get') {
            return $shown;
        }
    }

    return null;
}

/**
 * The first construct in this source that needs a session, or null.
 *
 * The six the plan names (a form that is not a read-only GET form, the CSRF
 * directive, a `csrf_` helper, and the `session(`, `old(` and `auth(` calls), plus
 * the shapes that reach the same state another way: the auth and session facades,
 * the `@auth` and `@guest` directives, and the error bag, which only exists
 * because a session middleware put it there. A `formmethod` attribute is one of
 * them too: it lets a button turn a GET form into a POST.
 *
 * ALSO EVERY WAY OF ASKING "WHO IS THIS": `@can`, `@cannot`, `@canany`, and
 * `request()->user(` and `$request->user(`. Each resolves the user, and resolving
 * the user is what reads the remember-me cookie, so a page that does it varies by
 * visitor whether or not it prints a name.
 *
 * A named function with samples, so it cannot quietly narrow.
 *
 * A BLADE COMMENT IS NOT SCANNED: it is removed before the template renders, so a
 * note explaining the rule may name a construct without being one. Anything after
 * the comment still is scanned.
 */
function firstSessionDependentConstruct(string $source): ?string
{
    $source = (string) preg_replace('/\{\{--.*?--\}\}/s', ' ', $source);

    if (firstNonGetForm($source) !== null) {
        return '<form';
    }

    $callable = '(?<![\w$>:])';

    $patterns = [
        'formmethod' => '/\bformmethod\s*=/i',
        '@csrf' => '/@csrf\b/',
        'csrf_' => '/csrf_/i',
        'session(' => '/'.$callable.'session\s*\(/',
        'old(' => '/'.$callable.'old\s*\(/',
        'auth(' => '/'.$callable.'auth\s*\(/',
        'Auth::/Session::' => '/\b(?:Auth|Session)::/',
        '@auth/@guest' => '/@(?:auth|guest)\b/',
        '@error/$errors' => '/@error\b|\$errors\b/',
        '@can/@cannot/@canany' => '/@(?:can|cannot|canany)\b/',
        'request()->user(' => '/(?:\brequest\s*\(\s*\)|\$request)\s*->\s*user\s*\(/',
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

    // str_contains rather than expect()->not->toContain(), whose failure message
    // would print the whole page.
    foreach (['<form', '_token', 'csrf'] as $needle) {
        expect(str_contains($body, $needle))->toBeFalse("The home page contains [{$needle}], which a sessionless page has no use for.");
    }
});

it('finds the files the source scan reads', function () {
    $names = array_map('basename', publicViewFilesForSessionScan());

    // The control: a scan over an empty list passes by finding nothing.
    expect($names)->toContain('public.blade.php')
        ->toContain('header.blade.php')
        ->toContain('navigation.blade.php')
        ->toContain('footer.blade.php')
        ->toContain('home.blade.php')
        ->toContain('brand.blade.php');
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
    // A form is a hit unless it says method="get": a post, a put, a form with no
    // method at all, the unquoted spelling, a get that sits inside another
    // attribute's value, a form that never closes, and a button that overrides it.
    "<form method='post'>",
    '<form method="put" action="/x">',
    '<form>',
    '<form method=get>',
    "<form action=\"/x\" title='method=\"get\"'>",
    '<form method="post"',
    '<form method="get">@csrf</form>',
    '<form method="get"></form><form method="post"></form>',
    '<form method="get"><button formmethod="post">x</button></form>',
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
    // Every way of resolving the user (and with it the remember-me cookie).
    "@can('update_student') x @endcan",
    "@cannot('update_student') x @endcannot",
    "@canany(['a', 'b']) x @endcanany",
    '{{ request()->user()->name }}',
    '{{ request ( ) -> user () }}',
    '{{ $request->user() }}',
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
    // Near misses of the user lookups: a different variable, a different method,
    // and a directive that merely begins with the same letters.
    '{{ $requestor->user() }} {{ $request->route() }} {{ $user->name }} @cancel',
    // A Blade comment is removed before rendering, so it may name a construct.
    '{{-- No @csrf and no session() on these pages. --}}',
    // The one form a public page may hold: a read-only GET form.
    '<form method="get" action="/x"><input name="q"></form>',
]);

it('accepts a read-only GET form, in either quote style, any case and any spacing', function (string $sample) {
    expect(firstNonGetForm($sample))->toBeNull()
        ->and(firstSessionDependentConstruct($sample))->toBeNull();
})->with([
    '<form method="get">',
    '<FORM METHOD="GET">',
    "<form method='get'>",
    '<form role="search" method="Get" action="/x">',
    // The tag spans lines and its action is an escaped echo holding a `>`: neither ends it early.
    "<form\n    method=\"get\"\n    action=\"{{ \$route->url }}\"\n    role=\"search\"\n>",
    '<form method="get"></form><form class="a" method="get"></form>',
    // The shape the publications search uses: the one echo is the action's URL.
    "<form method=\"get\" action=\"{{ PublicRoute::url('public.publications.index') }}\" role=\"search\" class=\"mt-8\">",
    // Spaces around the equals sign, a tab, a newline and a slash all separate attributes.
    '<form method = "get">',
    "<form\tmethod=\"get\"\n>",
    '<form/method="get">',
    '<form method="get"/>',
    '<FORM/METHOD = \'GET\'/>',
]);

it('refuses every form that is not a read-only GET form, returning its opening tag', function (string $sample, string $tag) {
    expect(firstNonGetForm($sample))->toBe($tag)
        ->and(firstSessionDependentConstruct($sample))->toBe('<form');
})->with([
    'post' => ['<form method="post"><input></form>', '<form method="post">'],
    'put' => ['<form method="PUT" action="/x">', '<form method="PUT" action="/x">'],
    'delete' => ["<form method='delete'>", "<form method='delete'>"],
    'no method' => ['<p>x</p><form action="/x">', '<form action="/x">'],
    'a bare form' => ['<form>', '<form>'],
    'unquoted get' => ['<form method=get>', '<form method=get>'],
    'an empty method' => ['<form method="">', '<form method="">'],
    'get only inside another value' => ["<form title='method=\"get\"'>", "<form title='method=\"get\"'>"],
    'the second form is the post' => ['<form method="get"></form><form method="post">', '<form method="post">'],
    'a form that never closes' => ['<form method="post"', '<form method="post"'],

    // The attribute NAME must be exactly `method`: a get in `data-method` is not one.
    'data-method get, real method post' => ['<form data-method="get" method="post">', '<form data-method="get" method="post">'],
    'a name that merely ends in method' => ['<form xmethod="get">', '<form xmethod="get">'],
    // Upper case, spaced and slash-separated posts are posts all the same.
    'upper-case post' => ['<FORM METHOD="POST">', '<FORM METHOD="POST">'],
    'spaced post' => ['<form method = "post">', '<form method = "post">'],
    'slash-separated post' => ['<form/method="post">', '<form/method="post">'],
    'a post first, a get after it' => ['<form method="post"/method="get">', '<form method="post"/method="get">'],

    // A Blade echo can hide a form or an attribute, so the tag holds one only in its action.
    'a tag name built by an echo' => ['<form{{ "" }} method="post">', '<form{{ }} method="post">'],
    'a whole form written in an echo' => ['{!! \'<form method="post">\' !!}', '<form (hidden in a Blade echo)'],
    'a form hidden in an escaped echo' => ['{{ \'<form method="post">\' }}', '<form (hidden in a Blade echo)'],
    'an attribute bag before the get' => ['<form {{ $attributes }} method="get">', '<form {{ }} method="get">'],
    'an unescaped bag after the get' => ['<form method="get" {!! $attributes !!}>', '<form method="get" {!! !!}>'],
    'an echo in another attribute' => ['<form method="get" class="{{ $class }}">', '<form method="get" class="{{ }}">'],
    'an echo as the method' => ['<form method="{{ $method }}" action="/x">', '<form method="{{ }}" action="/x">'],
    'an echo in data-action' => ['<form method="get" data-action="{{ $url }}">', '<form method="get" data-action="{{ }}">'],
    'an unescaped echo in the action' => ['<form method="get" action="{!! $url !!}">', '<form method="get" action="{!! !!}">'],
    'an echo in a second action' => ['<form method="get" action="/a" action="{{ $b }}">', '<form method="get" action="/a" action="{{ }}">'],
    'a directive in the tag' => ['<form method="get" action="/x" @if ($x) data-y @endif>', '<form method="get" action="/x" @if ($x) data-y @endif>'],
    'raw php in the tag' => ['<form <?php echo \'method="post"\'; ?> method="get">', '<form <?php echo \'method="post"\'; ?>'],
]);

it('refuses a button that overrides the form method', function () {
    expect(firstSessionDependentConstruct('<form method="get"><button formmethod="post">x</button></form>'))->toBe('formmethod');
});

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
    // The control for the 419 test above, and it proves something narrower than
    // that the binding is live. Without enforceCsrfCheck() this POST is waved
    // through and is not 419 either, so this test cannot tell the two apart.
    // Liveness is proven by the 419 test above, which gets 404 without the
    // binding. What this proves is that, with the real check running, the
    // refusal depends on the token: a valid one gets past it, so the 419 is the
    // check telling the two requests apart rather than refusing every POST.
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

    // The 419 proves the binding is live and the endpoint guarded. The second
    // request is only the control: with the check running, a valid token gets past it.
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
