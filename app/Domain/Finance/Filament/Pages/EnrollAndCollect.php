<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Pages;

use App\Domain\Enrollment\Enums\StudentStatus;
use App\Domain\Enrollment\Exceptions\BatchClosedException;
use App\Domain\Enrollment\Exceptions\DuplicateEnrollmentException;
use App\Domain\Enrollment\Exceptions\StudentNotEnrollableException;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Actions\EnrollAndBillAction;
use App\Domain\Finance\Actions\RecordPaymentAction;
use App\Domain\Finance\Data\EnrollAndBillData;
use App\Domain\Finance\Data\RecordPaymentData;
use App\Domain\Finance\Data\TenderData;
use App\Domain\Finance\Enums\TenderMethod;
use App\Domain\Finance\Exceptions\IdempotencyConflictException;
use App\Domain\Finance\Exceptions\PaymentExceedsOutstandingException;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Discount;
use App\Domain\Finance\Rules\NotACardNumber;
use App\Domain\Finance\Services\PricingService;
use App\Domain\Finance\Support\ChargeBalance;
use App\Domain\Finance\Support\Money;
use App\Models\User;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions as ActionsRow;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The phase 2 primary surface (design section 2): one guided flow, not a set
 * of CRUD screens the operator assembles.
 *
 * ALL EIGHT STEPS ARE HERE: find or create the student, select the batch, an
 * optional enrolment discount, a preview of the price, confirm — which
 * creates the enrolment and the bill in one transaction — optional
 * immediate collection, cash/card/split tenders, and finally the receipt.
 *
 * STEPS 6-8 ARE A SECOND, SEPARATE SCHEMA — NOT MORE WIZARD STEPS
 * -----------------------------------------------------------------
 * `confirm()` clears the enrol-and-bill Wizard's state (`$form`, steps 1-4
 * above) on success, so the next walk-in starts from empty fields rather
 * than the last student's.
 *
 * IT DOES NOT RETURN THE WIZARD TO STEP 1, AND AN EARLIER VERSION OF THIS
 * PARAGRAPH SAID IT DID. `$this->form->fill()` resets `$this->data`, not the
 * step: Filament keeps the current step in Alpine, seeded once as
 * `startStep` on a div carrying `wire:ignore.self` (`Wizard::render()`), and
 * `getCurrentStepIndex()` is per-request state no server call persists. The
 * page dispatches neither `go-to-wizard-step` nor `next-wizard-step`, so
 * after Confirm the operator is left on an emptied preview step. The
 * independent pre-PR review found the claim by reading Filament's own
 * source; it is recorded here rather than quietly deleted, because the
 * step-reset is a real gap worth closing and is a UI change that wants a
 * browser to verify, not a docblock edit.
 *
 * Collection is
 * therefore its own schema, `collectForm()` (statePath `collect`), rendered
 * only once `$collectionChargeId` is set — which `confirm()` does for an
 * actor holding `create_payment`, and never does otherwise. That mirrors
 * the discount step's own shape below: hidden as a courtesy, refused for
 * real by `RecordPaymentAction`'s own `Gate::forUser($actor)->authorize()`
 * if a crafted submission reaches `finalize()` regardless.
 *
 * `finalize()` AND `finishCollection()` ARE BARE LIVEWIRE METHODS, LIKE
 * confirm() — NOT MODAL ACTIONS
 * -----------------------------------------------------------------------
 * `collectAction()` and `doneAction()` below use the same
 * `Action::make(...)->action('methodName')` shape `confirmAction()` already
 * uses: a bare method-name string binds straight to `wire:click`, which
 * skips Filament's mount/unmount action lifecycle entirely
 * (`Filament\Actions\Concerns\HasAction::getLivewireClickHandler()`
 * returns the string as-is when the action carries one). That is load
 * bearing here, not incidental: an Action that unmounts itself the instant
 * its handler returns successfully cannot be called a second time to prove
 * anything about idempotency, and design section 5's whole retry-protection
 * story requires `finalize()` to be callable twice in a row with no
 * rerender between the calls and land on `RecordPaymentAction`'s replay
 * path the second time. `EnrollAndCollectFlowTest`'s double-submit case
 * calls the resolved instance's `finalize()` directly, twice, for exactly
 * that reason.
 *
 * THE IDEMPOTENCY KEY IS MINTED ONCE, WHEN THE PANEL FIRST APPEARS —
 * NEVER INSIDE finalize()
 * -----------------------------------------------------------------------
 * `beginCollection()` mints `$collectionIdempotencyKey` the moment
 * `$collectionChargeId` is set, i.e. the instant the collection panel
 * becomes relevant after a successful `confirm()`. `finalize()` reads that
 * same property on every call and never regenerates it — regenerating it
 * per submission is exactly the defect design section 5 names: a fresh key
 * per click turns a double-clicked button into two payments and two
 * receipts for one handover of cash, because both submissions would be
 * individually valid and `RecordPaymentAction`'s unique-index guard would
 * never see a collision to catch. A successful `finalize()` deliberately
 * does NOT clear the key either — see `finishCollection()`'s own docblock.
 *
 * STEP 4 (PREVIEW) AND STEP 5 (CONFIRM) SHARE ONE WIZARD STEP
 * -------------------------------------------------------------
 * The preview has nothing of its own to submit — it exists to be looked at
 * before the operator commits — so it is the Wizard's LAST step, and
 * `Wizard::submitAction()` supplies the "Confirm" button that step renders in
 * place of a "next" button. Confirming is therefore what clicking that button
 * on the preview step does, rather than a sixth array entry with a schema of
 * its own and nothing to show.
 *
 * THE DISCOUNT STEP HIDES; THE ACTION AUTHORIZES
 * -------------------------------------------------
 * discountStep() is `->visible()` on `apply_discount`, per design sections 2
 * and 10 — staff enrol walk-ins at full price and never see the step at all.
 * That visibility is a courtesy, not the guard: confirm() reads discount_id
 * from the schema's RAW state rather than its validated one (see confirm()'s
 * own docblock for why), so a crafted client-side update naming a discount
 * still reaches `EnrollAndBillAction`, and that Action's own
 * `Gate::authorize('apply_discount')` — server-side, unconditional — is what
 * actually refuses it. `EnrollAndCollectFlowTest` proves both halves.
 *
 * THE PREVIEW CALLS THE SAME `Money::afterDiscount()` THE BILL DOES
 * ---------------------------------------------------------------------
 * `previewFinalAmount()` below and `IssueChargeAction::execute()` compute the
 * discounted amount the same way: `PricingService::priceForBatch()` for the
 * list price, then `Money::afterDiscount()` on the chosen definition's
 * percentage. There is exactly one implementation of design section 3's
 * rounding rule, and this page calls it rather than repeating it — a second
 * implementation is how a preview and a bill come to disagree by a dirham.
 *
 * `extends Page`, NEVER `SimplePage`
 * ----------------------------------
 * See app/Filament/Pages/PasswordChange.php's own class docblock:
 * SimplePage extends BasePage, not Page, and the panel's discoverPages()
 * call filters on Page::class — so a class extending SimplePage would stop
 * being discovered and this route would silently cease to exist. This page
 * keeps the panel's ordinary chrome (topbar, sidebar, navigation), so there
 * is no reason to reach for SimplePage in the first place; it is named here
 * only because it is the mistake this file must not repeat.
 *
 * GATED ON create_enrollment, THE ABILITY THE WHOLE FLOW NEEDS
 * ---------------------------------------------------------------
 * Every step beyond this one assumes the actor may create an enrolment — an
 * actor who could search students and batches but not enrol anyone would
 * reach a dead end with no way to finish. canAccess() is Filament's page
 * authorization hook (Concerns\CanAuthorizeAccess): it is consulted on
 * mount and on every subsequent Livewire hydration of this page, not merely
 * on the first render, and a refusal aborts with a 403 before any of this
 * page's schema runs.
 *
 * STAFF NEVER SEE THE WORD "ALLOCATION"
 * ---------------------------------------
 * Design section 2 states this outright: the system allocates a payment to
 * a bill, and staff never make that decision or see the term. Nothing on
 * this page, its view, or lang/en/collect.php may use it —
 * EnrollAndCollectFlowTest scans all three.
 *
 * READS GO THROUGH THE ENROLLMENT MODELS DIRECTLY, NOT THROUGH
 * EnrollmentQueryService
 * ---------------------------------------------------------------
 * That service is the boundary for Finance reading ENROLMENT data — the
 * join from a charge or payment back to the student who owes it (its own
 * docblock lists the four consumers). Nothing here reads an Enrollment row
 * at all: step 1 reads Student directly, exactly as StudentResource and
 * EnrollmentsRelationManager already do, and step 2 reads Batch directly,
 * exactly as BatchResource already does. Both are read-only lookups feeding
 * a form the operator has not confirmed yet; no enrolment is created by this
 * unit.
 *
 * `$form` and `$collectForm` are resolved by Livewire's __get via
 * InteractsWithSchemas, discovered from the return type of a matching
 * zero-argument-or-Schema-argument method — see
 * `Filament\Schemas\Concerns\InteractsWithSchemas::cacheSchema()`. Declaring
 * both matches how Filament's own auth pages, and this panel's own
 * PasswordChange, type the same magic property.
 *
 * @property-read Schema $form
 * @property-read Schema $collectForm
 */
class EnrollAndCollect extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected string $view = 'filament.finance.enroll-and-collect';

    /**
     * How many students the picker offers for one search.
     *
     * A bound, not a preference — the same reasoning and the same figure
     * EnrollmentsRelationManager::SEARCH_RESULT_LIMIT already uses for the
     * identical query shape.
     */
    private const SEARCH_RESULT_LIMIT = 25;

    /** @var array<string, mixed> */
    public array $data = [];

    /**
     * Step 6-8's own backing state, separate from `$data` above because it
     * survives `confirm()`'s `$this->form->fill()` reset — see the class
     * docblock.
     *
     * @var array<string, mixed>
     */
    public array $collect = [];

    /**
     * The just-billed charge collection targets, or null before `confirm()`
     * has raised one, or once `finishCollection()` has closed the panel.
     *
     * THIS IS THE COURTESY VISIBILITY GUARD, NOT THE SECURITY BOUNDARY —
     * see the class docblock. `RecordPaymentAction`'s own
     * `Gate::forUser($actor)->authorize('create', Payment::class)` is what
     * actually refuses an actor without `create_payment`; this property
     * only decides whether the panel offering that button exists at all.
     */
    public ?int $collectionChargeId = null;

    /**
     * The idempotency key for the payment this panel would record, minted
     * once by `beginCollection()` and never regenerated — see the class
     * docblock's note on why `finalize()` must not mint its own.
     */
    public ?string $collectionIdempotencyKey = null;

    /**
     * The `<h1>` and the browser title.
     *
     * Filament falls back to `Str::headline(class_basename())` when these are
     * not overridden, which rendered a hardcoded English "Enroll And Collect"
     * — a user-facing string outside `__()` (CLAUDE.md non-negotiable 5), and
     * one that contradicted this page's own navigation label. Found by the
     * independent pre-PR review; `LocalizationTest`'s detector cannot catch it
     * because it matches `->label('literal')` shapes and this was an omission,
     * not a literal.
     */
    public function getTitle(): string
    {
        return __('collect.title');
    }

    public function getHeading(): string
    {
        return __('collect.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('collect.navigation_label');
    }

    /**
     * `auth()->user()?->can(...)`, not a Policy. There is no model this page
     * is "the" resource for — it is a flow spanning Student, Batch and,
     * later, Enrollment and Charge — so there is no single Policy class this
     * static hook would naturally belong to. This is exactly the
     * `$user->can('create_enrollment')` shape CLAUDE.md's first
     * non-negotiable requires, and the same ability
     * `App\Domain\Enrollment\Policies\EnrollmentPolicy::create()` checks for
     * creating an enrolment directly.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->can('create_enrollment') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Wizard::make([
                    $this->studentStep(),
                    $this->batchStep(),
                    $this->discountStep(),
                    $this->previewStep(),
                ])->submitAction($this->confirmAction()),
            ])
            ->statePath('data');
    }

    /**
     * Step 1: find or create the student.
     *
     * FIND: a bounded, server-side search, searchable rather than preloaded
     * — the same idiom EnrollmentsRelationManager::searchStudents() already
     * uses on the batch screen. It is reimplemented here rather than called
     * cross-domain: that class's own docblock says it is public and static
     * so it can be tested as itself, not so a different domain's page can
     * depend on an Enrollment resource's relation manager for its wording
     * and its shape.
     *
     * CREATE: a quick-create form, not the whole StudentResource form —
     * this is a guided flow, not a CRUD screen the operator assembles, and
     * these four fields are what the desk actually needs to identify a
     * walk-in. `Student::create()` is a plain write with no Action of its
     * own: CreateStudent's own docblock states "a student record is
     * ordinary data with no Action-owned write behaviour", so calling it
     * directly here bypasses no write boundary — unlike `enrollments`,
     * `students` carries no such boundary to bypass.
     */
    private function studentStep(): Step
    {
        return Step::make(__('collect.step_student'))
            ->schema([
                Select::make('student_id')
                    ->label(__('collect.student'))
                    ->required()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => self::searchStudents($search))
                    // Redisplaying an already-chosen value must not re-run
                    // the search; without this the field renders blank
                    // after a validation failure on the next step.
                    ->getOptionLabelUsing(fn (mixed $value): ?string => self::studentOptionLabel($value))
                    ->createOptionForm([
                        TextInput::make('student_code')
                            ->label(__('collect.student_code'))
                            ->required()
                            ->maxLength(30)
                            ->unique('students', 'student_code'),

                        TextInput::make('first_name')
                            ->label(__('collect.first_name'))
                            ->required()
                            ->maxLength(100),

                        TextInput::make('last_name')
                            ->label(__('collect.last_name'))
                            ->required()
                            ->maxLength(100),

                        TextInput::make('phone')
                            ->label(__('collect.phone'))
                            ->tel()
                            ->maxLength(30),
                    ])
                    ->createOptionUsing(fn (array $data): int => self::createStudent($data)),
            ]);
    }

    /**
     * Step 2: select the batch.
     *
     * Batch::scopeOpen() is the one place "still accepts enrolments" is
     * answered — its own docblock records that BatchTest keeps it in step
     * with acceptsEnrollments() — so reading it here, exactly as
     * BatchResource's own listing does not need to (it lists every batch),
     * is what keeps a completed or cancelled batch from ever being offered.
     */
    private function batchStep(): Step
    {
        return Step::make(__('collect.step_batch'))
            ->schema([
                Select::make('batch_id')
                    ->label(__('collect.batch'))
                    ->required()
                    ->searchable()
                    ->preload()
                    ->options(fn (): array => Batch::query()
                        ->open()
                        ->with('course')
                        ->orderBy('code')
                        ->get()
                        ->mapWithKeys(fn (Batch $batch): array => [
                            (int) $batch->getKey() => __('collect.batch_option', [
                                'code' => $batch->code,
                                'course' => $batch->course?->code,
                            ]),
                        ])
                        ->all()),
            ]);
    }

    /**
     * Step 3: an optional enrolment discount. Design sections 2 and 10:
     * admin and above only — staff enrol walk-ins at full price and hold no
     * financial ability at all.
     *
     * `->visible()` ON THE STEP, NOT `->disabled()` ON THE FIELD
     * -----------------------------------------------------------
     * A disabled field is still IN the form: its raw state still round-trips
     * through Livewire, and a staff member's browser would still receive the
     * full list of discount definitions in the page payload even though the
     * control was greyed out. `->visible()` keeps the step out of what is
     * rendered at all, matching design section 2's "the discount selector
     * does not render for them" — not "renders, greyed out".
     *
     * THIS IS A COURTESY, NOT THE GUARD — see the class docblock and
     * confirm()'s own docblock for how a crafted submission is refused
     * regardless of what this method decides.
     *
     * Only ACTIVE definitions are offered, the same reasoning batchStep()
     * gives for `Batch::scopeOpen()`: `EnrollAndBillAction` refuses a
     * deactivated discount server-side, and `Discount::scopeActive()` is
     * what keeps the picker from offering one nobody could actually choose.
     *
     * `->getOptionLabelUsing()` RESOLVES ANY DISCOUNT, NOT ONLY AN ACTIVE ONE
     * -----------------------------------------------------------------------------
     * Without it, Filament's own Select validation refuses a retired id with a
     * generic "The selected discount is invalid" error before `confirm()` is
     * ever reached — `getOptionLabel(withDefault: false)` falls back to a
     * lookup in `getOptions()`, which `Discount::scopeActive()` above has
     * already narrowed, so a retired id resolves to no label and Filament's
     * built-in "in options" rule fails the field. That is the wrong refusal
     * for design section 3's rule: getting a discount definition wrong has a
     * typed answer, `DiscountNotApplicableException` from
     * `EnrollAndBillAction`, and `EnrollAndCollectFlowTest`'s "does not offer
     * a retired discount" case asserts exactly that exception, not a
     * validation error standing in for it. Naming this closure lets any
     * discount id resolve to a label — active or not — so Filament's generic
     * check passes and the Action's own business-rule refusal is what
     * actually answers. The same closure also redisplays an already-chosen
     * value without re-querying `getOptions()`, the same reason
     * studentStep()'s own `getOptionLabelUsing()` exists.
     */
    private function discountStep(): Step
    {
        return Step::make(__('collect.step_discount'))
            ->visible(fn (): bool => auth()->user()?->can('apply_discount') ?? false)
            ->schema([
                Select::make('discount_id')
                    ->label(__('collect.discount'))
                    ->searchable()
                    ->live()
                    ->options(fn (): array => Discount::query()
                        ->active()
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn (Discount $discount): array => [
                            (int) $discount->getKey() => self::discountLabel($discount),
                        ])
                        ->all())
                    ->getOptionLabelUsing(fn (mixed $value): ?string => self::discountOptionLabel($value)),
            ]);
    }

    /**
     * Step 4: preview the original price, the discount, and the final
     * amount — the Wizard's last step, whose submit button IS step 5's
     * confirm (see the class docblock).
     *
     * Each entry recomputes on every render from `batch_id` and
     * `discount_id` via `Get`, rather than being written once when either
     * field changes — the three figures shown are therefore always the ones
     * the currently-selected batch and discount actually produce, never a
     * stale value left over from an earlier choice.
     */
    private function previewStep(): Step
    {
        return Step::make(__('collect.step_preview'))
            ->schema([
                TextEntry::make('preview_list_price')
                    ->label(__('collect.preview_list_price'))
                    ->state(function (Get $get): string {
                        $listPrice = self::previewListPrice($get('batch_id'));

                        return $listPrice === null
                            ? __('collect.preview_pending')
                            : self::formatMoney($listPrice->toDecimal());
                    }),

                TextEntry::make('preview_discount')
                    ->label(__('collect.preview_discount'))
                    ->state(function (Get $get): string {
                        $discount = self::previewDiscount($get('discount_id'));

                        return $discount === null
                            ? __('collect.no_discount')
                            : __('collect.discount_percentage_value', [
                                'percentage' => $discount->percentage,
                            ]);
                    }),

                TextEntry::make('preview_final_amount')
                    ->label(__('collect.preview_final_amount'))
                    ->state(function (Get $get): string {
                        $finalAmount = self::previewFinalAmount($get('batch_id'), $get('discount_id'));

                        return $finalAmount === null
                            ? __('collect.preview_pending')
                            : self::formatMoney($finalAmount->toDecimal());
                    }),
            ]);
    }

    /**
     * The Wizard's submit button on its last (preview) step. A bare string
     * action name, not a closure: Filament dispatches a click on an action
     * carrying one straight to the named method on this Livewire component —
     * `confirm()` below — with no modal and no schema of its own, because
     * everything it needs was already gathered by the earlier steps.
     */
    private function confirmAction(): Action
    {
        return Action::make('confirm')
            ->label(__('collect.confirm'))
            ->action('confirm');
    }

    /**
     * Step 5: confirm. Creates the enrolment and raises its bill in one
     * transaction, by calling `EnrollAndBillAction` and nothing else — see
     * the class docblock's note on `ActionBoundaryArchTest`.
     *
     * `discount_id` IS READ FROM THE RAW SCHEMA STATE, NOT FROM `getState()`
     * -------------------------------------------------------------------------
     * `$this->form->getState()` validates and DEHYDRATES the schema, and
     * Filament does not dehydrate a hidden component by default
     * (`Filament\Schemas\Concerns\CanBeHidden::isHiddenAndNotDehydratedWhenHidden()`) —
     * so for an actor without `apply_discount`, whose discount step is
     * `->visible(false)`, `getState()` would silently PRUNE `discount_id`
     * before this method ever saw it. That would turn a crafted client-side
     * update naming a discount into a silent full-price bill: exactly the
     * "filtering it out and billing full price" outcome
     * `EnrollAndBillAction`'s own docblock names as one of the two wrong
     * answers to a retired-or-unauthorized discount. Reading `$this->data`
     * directly instead means whatever was actually submitted reaches the
     * Action unchanged, and `Gate::authorize('apply_discount')` inside it —
     * server-side, unconditional — is what actually refuses it.
     *
     * `student_id` and `batch_id` come from `getState()`, because both
     * fields are always visible and `->required()`: validating them here,
     * before the Action ever runs, is what turns a missing selection into a
     * field error instead of a `TypeError` from an int cast on null.
     *
     * `beginCollection()` RUNS AFTER THE WIZARD RESETS, DELIBERATELY
     * -------------------------------------------------------------------
     * `$this->form->fill()` clears `$this->data` (steps 1-4's own state).
     * `$this->collect` is a separate property (see its own docblock), so
     * resetting the Wizard cannot disturb the collection panel this method
     * is about to open — but the ORDER still matters for one reason: were
     * `beginCollection()`'s own `$this->collectForm->fill()` to run BEFORE
     * `$this->form->fill()`, both calls would go through
     * `disableSchemaStateUpdateHooksForTesting()`-guarded state-update hooks
     * on the SAME Livewire component and the second `fill()` would be
     * filling a component whose "old state" bookkeeping the first fill just
     * populated for a different schema. Doing the Wizard's reset first
     * keeps the two fills independent of each other.
     */
    public function confirm(): void
    {
        $state = $this->form->getState();

        $rawDiscountId = $this->data['discount_id'] ?? null;

        /** @var User $actor */
        $actor = auth()->user();

        /*
         * EVERY REFUSAL `EnrollAndBillAction` CAN RAISE IS CAUGHT HERE.
         *
         * It propagates four: `DuplicateEnrollmentException`,
         * `BatchClosedException` and `StudentNotEnrollableException` from
         * `EnrollStudentAction`, plus `AuthorizationException` from its own
         * Gate checks. Uncaught, the most ordinary error at the desk —
         * enrolling a student who is already on that batch — is a 500 on the
         * phase's primary surface.
         *
         * Their messages go through `__()` precisely so they can be shown;
         * `DuplicateEnrollmentException`'s own docblock says it "reaches the
         * panel as a notification". `AuthorizationException` is the exception
         * to that: Laravel's own message is hardcoded English, so it maps to a
         * translated key instead.
         *
         * This is `EnrollmentsRelationManager::refuse()`'s pattern, which
         * already wraps the identical `EnrollAndBillAction` call in phase 1.
         * The independent pre-PR review found this half of the page missing it
         * while `finalize()` below had it — the pattern was known and applied
         * once.
         */
        try {
            $enrollment = app(EnrollAndBillAction::class)->execute($actor, new EnrollAndBillData(
                studentId: (int) $state['student_id'],
                batchId: (int) $state['batch_id'],
                discountId: filled($rawDiscountId) ? (int) $rawDiscountId : null,
            ));
        } catch (BatchClosedException|DuplicateEnrollmentException
            |StudentNotEnrollableException|AuthorizationException $exception) {
                $this->refuse($exception);

                return;
            }

        Notification::make()
            ->title(__('collect.confirmed'))
            ->success()
            ->send();

        $this->form->fill();

        $this->beginCollection($enrollment);
    }

    /**
     * Step 6's own opening: reveal the collection panel and mint the one
     * idempotency key its whole session of `finalize()` calls will share —
     * see the class docblock on why that minting happens here and nowhere
     * else.
     *
     * NEVER OPENED FOR AN ACTOR WITHOUT create_payment
     * -----------------------------------------------------
     * Design section 10: staff enrol walk-ins and hold no financial ability
     * at all. `create_payment` is seeded to admin and super_admin only
     * (`RolePermissionSeeder`), so a staff member's `confirm()` raises the
     * bill and stops there — the courtesy half of the same "hidden, not
     * merely disabled" shape `discountStep()` already uses.
     * `RecordPaymentAction`'s own Gate is what actually refuses a crafted
     * `finalize()` call regardless of what this check decides.
     *
     * THE DEFAULT AMOUNT AND FIRST TENDER READ ChargeBalance::outstandingFor(),
     * NOT $charge->amount
     * -----------------------------------------------------------------------------
     * Both agree at this exact instant — nothing has been paid against a
     * charge `EnrollAndBillAction` just created — but ChargeBalance is the
     * one definition of "what is still owed" everywhere else in this
     * domain (its own docblock), and defaulting through it rather than
     * through the charge's frozen `amount` column is what keeps this page
     * from becoming a second place that could one day disagree with it.
     */
    private function beginCollection(Enrollment $enrollment): void
    {
        if (! auth()->user()?->can('create_payment')) {
            $this->collectionChargeId = null;
            $this->collectionIdempotencyKey = null;

            return;
        }

        $charge = Charge::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->firstOrFail();

        $this->collectionChargeId = (int) $charge->getKey();
        $this->collectionIdempotencyKey = Str::uuid()->toString();

        $outstanding = ChargeBalance::outstandingFor($this->collectionChargeId)->toDecimal();

        $this->collectForm->fill([
            'amount' => $outstanding,
            'tenders' => [
                [
                    'method' => TenderMethod::Cash->value,
                    'amount' => $outstanding,
                    'external_reference' => null,
                ],
            ],
        ]);
    }

    /**
     * Steps 6-7: the amount to collect (full or an installment — the
     * operator types a figure, never a "full vs partial" choice, per design
     * section 2) and the tenders that make it up. Step 8, finalize, is
     * `collectAction()` below, embedded in this same schema rather than a
     * Wizard's `submitAction()` — there is no further step after it to be
     * the "last" one of.
     *
     * A SEPARATE SCHEMA, STATE-PATHED TO `collect`, NOT MORE `$data` KEYS
     * ------------------------------------------------------------------------
     * See the class docblock and `$collect`'s own docblock: this has to
     * survive `confirm()`'s reset of the enrol-and-bill Wizard, which a key
     * under `data` would not.
     *
     * NO `->numeric()` ANYWHERE HERE — see `ChargeResource::adjustAction()`'s
     * own docblock, which this page follows exactly: the regex validates the
     * same `decimal(12,3)` shape without installing Filament's float-casting
     * `NumberStateCast`.
     *
     * THE REPEATER'S ITEMS ARE FILLED WITH PLAIN ARRAY KEYS, NOT RANDOM ONES
     * -------------------------------------------------------------------------
     * `beginCollection()` above fills `tenders` as a plain zero-indexed PHP
     * array. Filament's Repeater only generates a random key for an item
     * added through its own "add" button in the browser; an item present in
     * the state a `fill()` call supplied keeps exactly the key that call
     * gave it. That is what lets a test address `tenders.0.external_reference`
     * directly instead of discovering a generated key first.
     *
     * `NotACardNumber` IS WIRED HERE — THIS UNIT IS ITS FIRST CALLER
     * -----------------------------------------------------------------
     * See that rule's own class docblock: it ships with no caller and names
     * this task's collection form as the field it belongs on.
     * `->required()` is conditioned on the SAME closure as `->visible()` so
     * a hidden, non-card reference is never demanded — Laravel's own
     * `required` rule already trims before comparing to an empty string
     * (`Illuminate\Validation\Concerns\ValidatesAttributes::validateRequired()`),
     * which is what turns a card tender's blank-after-trim reference into an
     * ordinary field error with no extra code here.
     *
     * WHY THE TENDER-TOTAL CHECK IS A FORM RULE HERE, AND NOT A CAUGHT
     * EXCEPTION IN `finalize()` AS `ChargeResource::adjustAction()` WOULD
     * SUGGEST
     * -----------------------------------------------------------------------------
     * `PaymentInvariantService::assertRecordable()` still refuses a mismatch
     * with a typed exception, under the charge's lock, exactly as design
     * section 5 requires — that guard is untouched and unbypassable from
     * here. But this page cannot import or catch that exception BY NAME:
     * `EnrollAndCollectFlowTest`'s own scan (kept from unit 1) fails the
     * build the instant a STRING LITERAL on this page contains the
     * substring — identifiers and comments are not scanned, so the typed
     * refusals this page catches may be named normally. The scan was
     * narrowed during the pre-PR review, after its own header already
     * claimed it read string literals while it read the whole source.
     */
    public function collectForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('amount')
                    ->label(__('collect.collect_amount'))
                    ->helperText(__('collect.collect_amount_hint'))
                    ->required()
                    ->inputMode('decimal')
                    ->rule('regex:/^\d{1,9}(\.\d{1,3})?$/')
                    ->rule(static fn (): Closure => self::positiveMoney())
                    ->rule(fn (Get $get): Closure => self::tenderTotalMatchesAmountRule($get))
                    ->validationMessages([
                        'regex' => __('collect.amount_format_error'),
                    ]),

                Repeater::make('tenders')
                    ->label(__('collect.tenders'))
                    ->schema([
                        Select::make('method')
                            ->label(__('collect.tender_method'))
                            ->options(self::tenderMethodOptions())
                            ->required()
                            ->live(),

                        TextInput::make('amount')
                            ->label(__('collect.tender_amount'))
                            ->required()
                            ->inputMode('decimal')
                            ->rule('regex:/^\d{1,9}(\.\d{1,3})?$/')
                            ->rule(static fn (): Closure => self::positiveMoney())
                            ->validationMessages([
                                'regex' => __('collect.amount_format_error'),
                            ]),

                        TextInput::make('external_reference')
                            ->label(__('collect.tender_reference'))
                            ->helperText(__('collect.tender_reference_hint'))
                            ->visible(fn (Get $get): bool => $get('method') === TenderMethod::Card->value)
                            ->required(fn (Get $get): bool => $get('method') === TenderMethod::Card->value)
                            ->rule(new NotACardNumber),
                    ])
                    ->columns(3)
                    ->minItems(1)
                    ->addActionLabel(__('collect.add_tender'))
                    ->live(),

                ActionsRow::make([
                    $this->collectAction(),
                    $this->doneAction(),
                ]),
            ])
            ->statePath('collect');
    }

    /**
     * The catalogue `Select::make('method')` above offers — every
     * `TenderMethod` case, translated. `TenderMethod` itself carries four
     * cases (design section 5's own note that `bank_transfer` and `other`
     * are wider than design sections 5 and 8's prose), so this offers all
     * four rather than only cash and card.
     *
     * @return array<string, string>
     */
    private static function tenderMethodOptions(): array
    {
        return collect(TenderMethod::cases())
            ->mapWithKeys(fn (TenderMethod $method): array => [$method->value => $method->label()])
            ->all();
    }

    /**
     * The validation closure behind the `amount` field's second rule — see
     * `collectForm()`'s own docblock for why this mirrors
     * `PaymentInvariantService::assertRecordable()`'s tender-total check as
     * an ordinary form rule rather than a caught exception.
     *
     * A MALFORMED FIGURE IS SILENTLY ACCEPTED HERE, DELIBERATELY
     * -----------------------------------------------------------------
     * Each individual amount already carries its own `regex:` rule. Summing
     * through `Money::fromDecimal()` on a value that already failed that
     * rule would throw an `InvalidArgumentException` — a developer
     * diagnostic, not a user-facing message (see `Money`'s own docblock) —
     * for a value the operator is about to see refused anyway by the rule
     * that actually owns that refusal. This rule answers "matches" for
     * anything it cannot parse exactly, so it never doubles up on, or
     * races, the regex rule's own error.
     */
    private static function tenderTotalMatchesAmountRule(Get $get): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            if (! self::isExactMoneyDecimal($value)) {
                return;
            }

            /** @var array<int, array<string, mixed>> $tenders */
            $tenders = $get('tenders') ?? [];

            $total = Money::zero();

            foreach ($tenders as $tender) {
                $tenderAmount = $tender['amount'] ?? null;

                if (! self::isExactMoneyDecimal($tenderAmount)) {
                    return;
                }

                $total = $total->add(Money::fromDecimal($tenderAmount));
            }

            if (! $total->equals(Money::fromDecimal($value))) {
                $fail(__('collect.tender_total_mismatch'));
            }
        };
    }

    /** Whether `$value` is a string this page's own money regex would accept. */
    private static function isExactMoneyDecimal(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{1,9}(\.\d{1,3})?$/', $value) === 1;
    }

    /**
     * Step 8: finalize. A bare Livewire method bound to a bare-string
     * Action — see the class docblock for why that shape (not a modal
     * Action with its own schema) is load-bearing here.
     *
     * `RecordPaymentData` IS BUILT WITH POSITIONAL ARGUMENTS, NOT NAMED ONES
     * -----------------------------------------------------------------------------
     * That DTO's second constructor parameter is `$allocation` — a name
     * this file cannot spell, for the same reason `collectForm()`'s own
     * docblock gives for not importing `TenderAllocationMismatchException`.
     * Named arguments are used here. An earlier version avoided them
     * because the copy scan read identifiers as well as literals; it no
     * longer does. Naming them matters on this call in particular:
     * `RecordPaymentData`'s second and fourth parameters are both
     * `string`, so a positional list would mis-bind the amount and the
     * idempotency key with no type error if either ever moved.
     */
    public function finalize(): void
    {
        if ($this->collectionChargeId === null || $this->collectionIdempotencyKey === null) {
            return;
        }

        $state = $this->collectForm->getState();

        /** @var array<int, array{method: string, amount: string, external_reference: ?string}> $tenderStates */
        $tenderStates = $state['tenders'];

        $tenders = array_map(
            fn (array $tender): TenderData => new TenderData(
                TenderMethod::from((string) $tender['method']),
                (string) $tender['amount'],
                filled($tender['external_reference'] ?? null) ? (string) $tender['external_reference'] : null,
            ),
            $tenderStates,
        );

        $payload = new RecordPaymentData(
            chargeId: $this->collectionChargeId,
            allocation: (string) $state['amount'],
            tenders: $tenders,
            idempotencyKey: $this->collectionIdempotencyKey,
        );

        /** @var User $actor */
        $actor = auth()->user();

        try {
            app(RecordPaymentAction::class)->execute($actor, $payload);
        } catch (IdempotencyConflictException) {
            /*
             * A SECOND INSTALLMENT IN THE SAME PANEL SESSION, NOT A REPLAY.
             *
             * The key is minted once per collection panel and deliberately not
             * cleared after a successful payment — that is what makes a
             * double-clicked button one payment. The cost is that a genuine
             * second collection typed into the same open panel arrives with
             * the same key and a different fingerprint, which
             * `RecordPaymentAction` correctly refuses.
             *
             * Uncaught, that refusal is a 500 and the money is silently not
             * recorded. Caught, the operator is told to reopen the bill for
             * the next installment. The fix is a catch, not a key change:
             * clearing the key here would reopen the double-submit hole the
             * key exists to close.
             */
            Notification::make()
                ->title(__('collect.reopen_for_next_installment'))
                ->danger()
                ->persistent()
                ->send();

            return;
        } catch (PaymentExceedsOutstandingException $exception) {
            Notification::make()
                ->title($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('collect.collected_successfully'))
            ->success()
            ->send();
    }

    /**
     * The "Collect payment" button — step 8's finalize, embedded in the
     * collection schema rather than a Wizard `submitAction()` (see
     * `collectForm()`'s own docblock).
     */
    private function collectAction(): Action
    {
        return Action::make('collect')
            ->label(__('collect.collect_action'))
            ->action('finalize');
    }

    /**
     * "Done" — closes the collection panel, whether or not anything was
     * ever collected against it. This is what makes collection genuinely
     * OPTIONAL (design section 2): the operator may click this having typed
     * nothing into `collectForm` at all, leaving the enrolment and its bill
     * exactly as `confirm()` left them.
     */
    private function doneAction(): Action
    {
        return Action::make('done')
            ->label(__('collect.done'))
            ->color('gray')
            ->action('finishCollection');
    }

    /**
     * Close the collection panel. See `doneAction()` for the "skip
     * entirely" case and the class docblock for why a SUCCESSFUL
     * `finalize()` does not call this itself: doing so would clear
     * `$collectionIdempotencyKey` the instant the first click of a double
     * submit succeeds, so the second click would mint a fresh key instead
     * of reusing the first one — silently reintroducing the exact defect
     * design section 5's idempotency key exists to remove.
     */
    public function finishCollection(): void
    {
        $this->collectionChargeId = null;
        $this->collectionIdempotencyKey = null;
        $this->collectForm->fill();
    }

    /**
     * The batch's list price for the preview, or null before one is chosen.
     *
     * PUBLIC AND STATIC for the same testability reason as searchStudents()
     * below: this is the exact closure previewStep()'s first TextEntry
     * calls, so testing it directly tests the real behaviour. Delegates to
     * `PricingService`, the one definition of a batch's effective price —
     * see that class's own docblock — rather than reading `$batch->price`
     * directly, which would silently drop the course-default inheritance a
     * null batch price carries.
     */
    public static function previewListPrice(mixed $batchId): ?Money
    {
        if (blank($batchId)) {
            return null;
        }

        $batch = Batch::query()->with('course')->find($batchId);

        if (! $batch instanceof Batch) {
            return null;
        }

        return app(PricingService::class)->priceForBatch($batch);
    }

    /**
     * The chosen discount definition for the preview, or null when none is
     * chosen or the id no longer resolves.
     *
     * Reads the definition directly rather than through `Discount::active()`
     * deliberately: a retired discount smuggled past the picker (see
     * EnrollAndCollectFlowTest's "does not offer a retired discount" case)
     * must still preview as ITSELF, not silently as "no discount" — the
     * operator should see exactly what they are about to have refused, and
     * `confirm()`'s call into `EnrollAndBillAction` is what actually refuses
     * it.
     */
    public static function previewDiscount(mixed $discountId): ?Discount
    {
        if (blank($discountId)) {
            return null;
        }

        $discount = Discount::query()->find($discountId);

        return $discount instanceof Discount ? $discount : null;
    }

    /**
     * The amount that would actually be billed for the current batch and
     * discount choice, or null before a batch is chosen.
     *
     * `Money::afterDiscount()` AND NOTHING ELSE — see the class docblock.
     * This is the one place this page computes a discounted figure, and
     * `IssueChargeAction::execute()` is the only other place in the system
     * that does; both call this same method on `Money`, so the preview and
     * the frozen charge cannot compute the rule two different ways and
     * disagree by a dirham.
     */
    public static function previewFinalAmount(mixed $batchId, mixed $discountId): ?Money
    {
        $listPrice = self::previewListPrice($batchId);

        if ($listPrice === null) {
            return null;
        }

        $discount = self::previewDiscount($discountId);

        return $discount === null ? $listPrice : $listPrice->afterDiscount($discount->percentage);
    }

    /**
     * The label for an already-selected discount, redisplayed without
     * re-querying `discountStep()`'s own (active-only) options list.
     *
     * PUBLIC AND STATIC for the same testability reason as
     * `studentOptionLabel()`, and because it is the exact closure
     * `discountStep()`'s `->getOptionLabelUsing()` registers. Resolves ANY
     * discount by id, retired ones included — see `discountStep()`'s own
     * docblock for why that is load-bearing rather than incidental: it is
     * what keeps Filament's built-in "in options" validation from refusing a
     * retired id before `EnrollAndBillAction` gets to.
     */
    public static function discountOptionLabel(mixed $value): ?string
    {
        $discount = Discount::query()->find($value);

        return $discount instanceof Discount ? self::discountLabel($discount) : null;
    }

    /** The composite label shown for one discount definition, wherever it is offered or redisplayed. */
    private static function discountLabel(Discount $discount): string
    {
        return __('collect.discount_option', [
            'name' => $discount->name,
            'percentage' => $discount->percentage,
        ]);
    }

    /**
     * The one place this page assembles a dirham amount into user-facing
     * text. The same reasoning as `ChargeResource::formatMoney()`: the
     * separator, symbol and ordering are all localisable, so this goes
     * through a translation key rather than being concatenated in code.
     * Takes the raw decimal string `Money::toDecimal()` produces, never a
     * `Money` itself — `Money` deliberately has no string conversion.
     */
    private static function formatMoney(string $decimal): string
    {
        return __('collect.amount_lyd', ['amount' => $decimal]);
    }

    /**
     * The bounded, server-side student search behind step 1's picker.
     *
     * PUBLIC AND STATIC SO IT CAN BE TESTED AS ITSELF — the same reasoning
     * EnrollmentsRelationManager::searchStudents() states for the identical
     * shape: reaching it through a mounted schema's search-results call
     * means asserting against Filament's component internals, which change
     * between releases.
     *
     * student_code matches from the START — it is an identifier read off a
     * form, so a prefix match is what is expected, and it stays
     * index-friendly. Either name matches anywhere, because people search
     * for "zarrouk" without knowing which field it lives in.
     *
     * @return array<int, string> student id => label
     */
    public static function searchStudents(string $search): array
    {
        return Student::query()
            ->where(fn (Builder $query): Builder => $query
                ->where('student_code', 'like', $search.'%')
                ->orWhere('first_name', 'like', '%'.$search.'%')
                ->orWhere('last_name', 'like', '%'.$search.'%'))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(self::SEARCH_RESULT_LIMIT)
            ->get()
            ->mapWithKeys(fn (Student $student): array => [
                (int) $student->getKey() => self::studentLabel($student),
            ])
            ->all();
    }

    /**
     * The label for an already-selected student, redisplayed without
     * searching. Null when the id resolves to nothing, which Filament
     * renders as an empty field rather than throwing.
     */
    public static function studentOptionLabel(mixed $value): ?string
    {
        $student = Student::query()->find($value);

        return $student instanceof Student ? self::studentLabel($student) : null;
    }

    /**
     * Create a walk-in student from step 1's quick-create form and hand back
     * the id the Select field stores as its state.
     *
     * PUBLIC AND STATIC for the same testability reason as searchStudents()
     * above, and because it is the exact closure `createOptionUsing()`
     * registers — testing it directly tests the real behaviour.
     *
     * Always Prospective: a walk-in captured here has not yet been placed on
     * a batch, which is what step 2 is for. StudentFactory's own default is
     * Active precisely because most fixtures represent someone already
     * studying; a freshly captured walk-in is not that yet.
     *
     * @param  array<string, mixed>  $data
     */
    public static function createStudent(array $data): int
    {
        $student = Student::create([
            ...$data,
            'status' => StudentStatus::Prospective,
        ]);

        return (int) $student->getKey();
    }

    /** Include code and name, so two students with the same name stay distinguishable. */
    private static function studentLabel(Student $student): string
    {
        return __('collect.student_option_label', [
            'code' => $student->student_code,
            'name' => $student->full_name,
        ]);
    }

    /**
     * Show a refusal the operator can act on, instead of a 500.
     *
     * `EnrollmentsRelationManager::refuse()`'s shape, for the same Action and
     * the same reasons: the domain exceptions carry translated messages meant
     * to be read, while `AuthorizationException`'s message is Laravel's own
     * hardcoded English and is replaced with a translated key. Persistent,
     * because a refusal the operator scrolls past is a refusal they will
     * retry blind.
     */
    /**
     * Money the operator typed must be more than nothing.
     *
     * The shape regex above accepts `0.000`, and nothing else refused it — so
     * a spare tender row left at zero satisfied the tender-total rule and then
     * reached `TenderData`, whose constructor throws a developer-facing
     * `InvalidArgumentException` in English. `Money`'s own docblock names that
     * outcome as a bug in the validation rather than in the wording: "if one
     * of these ever reaches a user, the missing validation rule is the bug".
     * This is that rule. Found by the independent pre-PR review.
     *
     * Positivity is asked of `Money` rather than re-expressed as a regex or a
     * numeric comparison, so the panel and the `CHECK (amount > 0)` on
     * `payment_tenders` cannot disagree about what "nothing" means.
     */
    private static function positiveMoney(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            try {
                $amount = Money::fromDecimal($value);
            } catch (InvalidArgumentException) {
                // The shape rule owns malformed input and reports it itself.
                return;
            }

            if (! $amount->isPositive()) {
                $fail(__('collect.amount_must_be_positive'));
            }
        };
    }

    private function refuse(
        BatchClosedException|DuplicateEnrollmentException
        |StudentNotEnrollableException|AuthorizationException $exception,
    ): void {
        Notification::make()
            ->title($exception instanceof AuthorizationException
                ? __('collect.enrollment_denied')
                : $exception->getMessage())
            ->danger()
            ->persistent()
            ->send();
    }
}
