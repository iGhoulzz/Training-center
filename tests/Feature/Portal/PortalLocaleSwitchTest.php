<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Student;
use App\Livewire\PortalLocaleSwitch;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The student portal's language switch (P4-T42)
|--------------------------------------------------------------------------
|
| A Livewire component in the portal's topbar for a signed-in student, and two
| plain links on the sign-in page for a guest.
|
| TWO KINDS OF TEST, FOR TWO KINDS OF CLAIM. What the component does with its
| input (who may call it, what it refuses, how often) is driven with
| Livewire::test(). What the PANEL does around it (a password change that must
| hold the student, the language the next page is drawn in) cannot be proved
| that way: Livewire applies persistent middleware only to a request that hits
| its update route, and Livewire::test() never routes (LivewirePersistentGuardTest
| explains it). Those cases load a real portal page, take the component's signed
| snapshot out of the HTML and post it back, as a browser does.
|
| EVERY ACTOR HOLDS EXACTLY THE ABILITIES THE CASE NEEDS, never the seeded student
| role and never a super admin, so a check on the wrong ability fails a test.
| EVERY EXPECTED VALUE IS WRITTEN OUT BY HAND.
*/

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    Livewire::flushState();

    /**
     * A portal-eligible student: live, linked to a student record, holding exactly
     * the abilities named.
     *
     * @param  array<int, string>  $abilities
     * @param  array<string, mixed>  $attributes
     */
    $this->makeStudent = function (array $abilities = ['access_student_portal', 'view_own_student_record'], array $attributes = []): User {
        $user = User::factory()->create([
            'is_active' => true,
            'must_change_password' => false,
            'password' => Hash::make('existing-password-1'),
            'locale' => 'en',
            ...$attributes,
        ]);
        Student::factory()->for($user)->create();
        $user->givePermissionTo($abilities);

        return $user->refresh();
    };

    /** The signed snapshot of the switch on a real portal page, as a browser would post it back. */
    $this->openSwitch = function (string $url): string {
        $html = (string) $this->get($url)->assertSuccessful()->getContent();
        $name = app('livewire.factory')->resolveComponentName(PortalLocaleSwitch::class);

        preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

        foreach ($matches[1] as $raw) {
            $candidate = html_entity_decode($raw, ENT_QUOTES);

            if ((json_decode($candidate, true)['memo']['name'] ?? null) === $name) {
                return $candidate;
            }
        }

        throw new RuntimeException("No rendered snapshot for the language switch on {$url}.");
    };

    $this->postSwitch = fn (string $snapshot, string $locale) => $this
        ->withHeaders(['X-Livewire' => 'true'])
        ->postJson(app(HandleRequests::class)->getUpdateUri(), ['components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => 'switchLocale', 'params' => [$locale]]],
        ]]]);

    // Audit rows that record a change of language, however many other changes the user had.
    $this->localeChanges = fn (): int => Activity::query()
        ->where('subject_type', User::class)
        ->where('event', 'updated')
        ->whereNotNull('attribute_changes->attributes->locale')
        ->count();
});

/**
 * Sign in on the student guard and call the switch, returning the component so the
 * caller asserts how it answered. An abort() is not thrown out of Livewire::test():
 * it becomes the response the assertions below read.
 *
 * @return Testable
 */
function portalSwitchCall(User $actor, string $locale)
{
    test()->actingAs($actor, 'student');

    return Livewire::test(PortalLocaleSwitch::class)->call('switchLocale', $locale);
}

/*
|--------------------------------------------------------------------------
| The signed-in switch: drawn, and what it does
|--------------------------------------------------------------------------
*/

it('draws the switch in the topbar of a portal page, with the current language marked', function () {
    $student = ($this->makeStudent)();

    $html = (string) $this->actingAs($student, 'student')->get('/portal/overview')->assertSuccessful()->getContent();
    $switch = HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelector('.fi-topbar [data-locale-switch]');

    expect($switch)->not->toBeNull('The topbar has no language switch.')
        ->and($switch->getAttribute('data-current'))->toBe('en')
        ->and($switch->querySelector('[aria-current="true"]')?->textContent)->toBe('EN')
        ->and($switch->querySelector('[aria-current="true"]')?->tagName)->toBe('SPAN');

    $other = $switch->querySelector('button[lang="ar"]');

    expect($other)->not->toBeNull('There is no button for Arabic.')
        ->and($other->textContent)->toBe('ع')
        ->and($other->getAttribute('aria-label'))->toBe('العربية')
        ->and($other->getAttribute('wire:click'))->toBe("switchLocale('ar')");
});

it('has no public property that names a user, and takes only the language', function () {
    $reflection = new ReflectionClass(PortalLocaleSwitch::class);

    expect($reflection->getProperties(ReflectionProperty::IS_PUBLIC))->toBe([]);

    $parameters = array_map(
        fn (ReflectionParameter $parameter): string => $parameter->getName(),
        $reflection->getMethod('switchLocale')->getParameters(),
    );

    // The Action is injected by the container; the caller supplies the language and nothing else.
    expect($parameters)->toBe(['locale', 'action']);
});

it('sets the signed-in student own language and reloads the page they are on', function () {
    $student = ($this->makeStudent)();

    $this->actingAs($student, 'student');

    $snapshot = ($this->openSwitch)('/portal/overview');

    $response = ($this->postSwitch)($snapshot, 'ar')->assertSuccessful();

    expect($student->refresh()->locale)->toBe('ar')
        ->and($response->json('components.0.effects.redirect'))->toBe(url('/portal/overview'));
});

it('draws the next portal page in Arabic and right to left once the student has chosen it', function () {
    $student = ($this->makeStudent)();
    $this->actingAs($student, 'student');

    ($this->postSwitch)(($this->openSwitch)('/portal/overview'), 'ar')->assertSuccessful();

    // A previous request's locale must not be what makes the next page Arabic.
    app()->setLocale('en');

    $html = (string) $this->get('/portal/overview')->assertSuccessful()->getContent();
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);

    expect($document->documentElement->getAttribute('lang'))->toBe('ar')
        ->and($document->documentElement->getAttribute('dir'))->toBe('rtl')
        ->and($document->querySelector('h1')?->textContent)->toContain('نظرة عامة')
        ->and($document->querySelector('.fi-topbar [data-locale-switch]')?->getAttribute('data-current'))->toBe('ar');
});

it('sets the language back to English from Arabic', function () {
    $student = ($this->makeStudent)(attributes: ['locale' => 'ar']);
    $this->actingAs($student, 'student');

    ($this->postSwitch)(($this->openSwitch)('/portal/overview'), 'en')->assertSuccessful();

    expect($student->refresh()->locale)->toBe('en');
});

it('never changes another account', function () {
    $actor = ($this->makeStudent)();
    $bystander = ($this->makeStudent)();
    $staff = User::factory()->create(['locale' => 'en']);

    $this->actingAs($actor, 'student');

    Livewire::test(PortalLocaleSwitch::class)->call('switchLocale', 'ar');

    expect($actor->refresh()->locale)->toBe('ar')
        ->and($bystander->refresh()->locale)->toBe('en')
        ->and($staff->refresh()->locale)->toBe('en')
        ->and(($this->localeChanges)())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| What it refuses, each with no write
|--------------------------------------------------------------------------
*/

it('refuses a language the application cannot render, and writes nothing', function (string $locale) {
    $student = ($this->makeStudent)();
    $this->actingAs($student, 'student');

    Livewire::test(PortalLocaleSwitch::class)
        ->call('switchLocale', $locale)
        ->assertHasErrors('locale')
        ->assertNoRedirect();

    expect($student->refresh()->locale)->toBe('en')
        ->and(($this->localeChanges)())->toBe(0);
})->with([
    'a language the application does not have' => ['fr'],
    'a different case' => ['AR'],
    'an empty value' => [''],
]);

it('refuses an account the student panel does not admit, with a 403 and no write', function (array $abilities, array $attributes) {
    $student = ($this->makeStudent)($abilities, $attributes);

    portalSwitchCall($student, 'ar')->assertForbidden()->assertNoRedirect();

    expect($student->refresh()->locale)->toBe('en')
        ->and(($this->localeChanges)())->toBe(0);
})->with([
    'a deactivated student' => [['access_student_portal', 'view_own_student_record'], ['is_active' => false]],
    'a student without the portal permission' => [['view_own_student_record'], []],
]);

it('refuses a user with no student record, with a 403 and no write', function () {
    $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
    $user->givePermissionTo('access_student_portal');

    portalSwitchCall($user, 'ar')->assertForbidden();

    expect($user->refresh()->locale)->toBe('en');
});

it('refuses a guest, with a 403 and no write', function () {
    $user = User::factory()->create(['locale' => 'en']);

    Livewire::test(PortalLocaleSwitch::class)->call('switchLocale', 'ar')->assertForbidden();

    expect($user->refresh()->locale)->toBe('en');
});

it('refuses a staff sign-in that is not a student, and writes nothing', function () {
    $staff = User::factory()->create(['is_active' => true, 'locale' => 'en']);
    $staff->givePermissionTo('access_admin_panel');

    // Signed in on the web guard only: the student guard holds nobody.
    $this->actingAs($staff, 'web');

    Livewire::test(PortalLocaleSwitch::class)->call('switchLocale', 'ar')->assertForbidden();

    expect($staff->refresh()->locale)->toBe('en');
});

it('holds a student who must change their password: the panel redirects an open page and nothing is written', function () {
    $student = ($this->makeStudent)();
    $this->actingAs($student, 'student');

    // The page was opened while the account was in good standing.
    $snapshot = ($this->openSwitch)('/portal/overview');

    // ONLY the flag: a changed hash would let AuthenticateSession refuse first.
    $student->forceFill(['must_change_password' => true])->save();

    ($this->postSwitch)($snapshot, 'ar')->assertRedirect('/portal/password-change');

    expect($student->refresh()->locale)->toBe('en')
        ->and(($this->localeChanges)())->toBe(0);
});

it('ends the session of a student who was deactivated after opening the page, and writes nothing', function () {
    $student = ($this->makeStudent)();
    $this->actingAs($student, 'student');

    $snapshot = ($this->openSwitch)('/portal/overview');

    $student->forceFill(['is_active' => false])->save();

    ($this->postSwitch)($snapshot, 'ar')->assertRedirectContains('/portal/login');

    expect($student->refresh()->locale)->toBe('en');
});

/*
|--------------------------------------------------------------------------
| The rate limit
|--------------------------------------------------------------------------
*/

it('allows ten changes a minute and refuses the eleventh without writing', function () {
    $student = ($this->makeStudent)();
    $this->actingAs($student, 'student');

    RateLimiter::clear('portal-locale:'.$student->getKey());

    // Ten real changes, alternating so each one writes: the tenth leaves 'en'.
    foreach (range(1, 10) as $attempt) {
        Livewire::test(PortalLocaleSwitch::class)
            ->call('switchLocale', $attempt % 2 === 1 ? 'ar' : 'en')
            ->assertRedirect();
    }

    expect($student->refresh()->locale)->toBe('en')
        ->and(($this->localeChanges)())->toBe(10);

    // The eleventh would have written 'ar'.
    portalSwitchCall($student, 'ar')->assertStatus(429)->assertNoRedirect();

    expect($student->refresh()->locale)->toBe('en')
        ->and(($this->localeChanges)())->toBe(10);

    // The budget is the user's own: another student is not held by it.
    $other = ($this->makeStudent)();

    portalSwitchCall($other, 'ar')->assertRedirect();

    expect($other->refresh()->locale)->toBe('ar');
});

/*
|--------------------------------------------------------------------------
| The guest's switch on the sign-in page
|--------------------------------------------------------------------------
*/

it('offers a guest links to the sign-in page in each language, the current one not a link', function () {
    $html = (string) $this->get('/portal/login')->assertOk()->getContent();
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $switch = $document->querySelector('[data-locale-switch]');

    expect($switch)->not->toBeNull('The sign-in page has no language switch.')
        ->and($switch->getAttribute('data-current'))->toBe('en')
        ->and($switch->querySelector('span[aria-current="true"]')?->textContent)->toBe('EN')
        ->and($switch->querySelector('a[hreflang="ar"]')?->getAttribute('href'))->toBe(url('/portal/login').'?locale=ar')
        ->and($switch->querySelector('a[hreflang="ar"]')?->getAttribute('aria-label'))->toBe('العربية')
        ->and($switch->querySelector('a[hreflang="en"]'))->toBeNull()
        ->and($switch->querySelector('button'))->toBeNull('A guest switch must not be a component that writes.');
});

it('draws the sign-in page in Arabic when the guest asks for it, and offers the way back', function () {
    $html = (string) $this->get('/portal/login?locale=ar')->assertOk()->getContent();
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $switch = $document->querySelector('[data-locale-switch]');

    expect($document->documentElement->getAttribute('lang'))->toBe('ar')
        ->and($document->documentElement->getAttribute('dir'))->toBe('rtl')
        ->and($switch->getAttribute('data-current'))->toBe('ar')
        ->and($switch->querySelector('span[aria-current="true"]')?->textContent)->toBe('ع')
        ->and($switch->querySelector('a[hreflang="en"]')?->getAttribute('href'))->toBe(url('/portal/login').'?locale=en')
        ->and($switch->querySelector('a[hreflang="en"]')?->getAttribute('aria-label'))->toBe('English');
});

it('does not draw the guest switch on the admin sign-in page, nor the signed-in one', function () {
    $this->get('/admin/login')->assertOk()->assertDontSee('tc-portal-locale', false);

    $student = ($this->makeStudent)();

    // The password form is a signed-in page on the simple layout: no guest links there.
    $this->actingAs($student, 'student')->get('/portal/password-change')->assertOk()->assertDontSee('tc-portal-locale', false);
});

it('keeps the admin language route closed to a student', function () {
    $student = ($this->makeStudent)();

    // On the student guard alone, as in a request to the route (LocaleSwitchTest explains why).
    Auth::guard('student')->setUser($student);

    $this->post(route('account.locale.update'), ['locale' => 'ar'])->assertForbidden();

    expect($student->refresh()->locale)->toBe('en');
});
