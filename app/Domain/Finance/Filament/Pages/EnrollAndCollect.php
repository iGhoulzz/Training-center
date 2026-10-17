<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Pages;

use App\Domain\Enrollment\Actions\CreateWithIdentifierCodeAction;
use App\Domain\Enrollment\Enums\StudentStatus;
use App\Domain\Enrollment\Exceptions\BatchClosedException;
use App\Domain\Enrollment\Exceptions\DuplicateEnrollmentException;
use App\Domain\Enrollment\Exceptions\IdentifierCodeAlreadyUsedException;
use App\Domain\Enrollment\Exceptions\IdentifierCodeExhaustedException;
use App\Domain\Enrollment\Exceptions\StudentNotEnrollableException;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Enrollment\Services\EnrollmentQueryService;
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
use App\Domain\Finance\Models\Payment;
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
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions as ActionsRow;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Four visual stages around the existing financial Action boundary.
 *
 * The Collect stage previews the bill before explicit confirmation, then
 * offers optional payment. Selection schemas remain mounted for validation
 * and the permission-gated quick-create Action, while card views render only
 * the current stage. Navigation is server-owned so Done reliably returns
 * the next walk-in to Student.
 *
 * Payment keys are minted once by beginCollection(), retained across retries
 * and successful payment, and cleared only by explicit finishCollection().
 *
 * @property-read Schema $form
 * @property-read Schema $collectForm
 * @property-read array{student: string, batch: string, list: ?Money, discount: string, amount: ?Money, reference: ?string, receipt: ?string} $bill
 * @property-read bool $tendersBalanced
 * @property-read Collection<int, Student> $studentMatches
 * @property-read Collection<int, Batch> $batchChoices
 * @property-read array<int, array{id: int, label: string, amount: ?Money}> $discountChoices
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

    #[Locked]
    public int $flowStep = 1;

    public string $studentSearch = '';

    public string $batchSearch = '';

    public string $discountSearch = '';

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

    public function getSubheading(): string
    {
        return __('collect.front_desk');
    }

    /** @return array{student: string, batch: string, list: ?Money, discount: string, amount: ?Money, reference: ?string, receipt: ?string} */
    #[Computed]
    public function bill(): array
    {
        if ($this->collectionChargeId !== null && auth()->user()?->can('create_payment')) {
            $charge = Charge::query()->find($this->collectionChargeId);
            if ($charge !== null) {
                $context = app(EnrollmentQueryService::class)->receiptContextFor($charge->enrollment_id);

                return [
                    'student' => $context['student_name'], 'batch' => $context['batch_code'],
                    'list' => Money::fromDecimal($charge->list_price),
                    'discount' => $charge->discount_percentage === null ? __('collect.no_discount')
                        : __('collect.discount_percentage_value', ['percentage' => $charge->discount_percentage]),
                    'amount' => Money::fromDecimal($charge->amount), 'reference' => $charge->reference,
                    'receipt' => Payment::query()->where('idempotency_key', $this->collectionIdempotencyKey)->value('reference'),
                ];
            }
        }
        $list = self::previewListPrice($this->data['batch_id'] ?? null);
        $discount = auth()->user()?->can('apply_discount') ? self::previewDiscount($this->data['discount_id'] ?? null) : null;

        return [
            'student' => filled($this->data['student_id'] ?? null) ? (self::studentOptionLabel($this->data['student_id']) ?? __('collect.not_chosen')) : __('collect.not_chosen'),
            'batch' => filled($this->data['batch_id'] ?? null) ? (self::batchOptionLabel($this->data['batch_id']) ?? __('collect.not_chosen')) : __('collect.not_chosen'),
            'list' => $list, 'discount' => $discount === null ? __('collect.no_discount') : self::discountLabel($discount),
            'amount' => $discount === null ? $list : $list?->afterDiscount($discount->percentage), 'reference' => null, 'receipt' => null,
        ];
    }

    #[Computed]
    public function tendersBalanced(): bool
    {
        if (! self::isExactMoneyDecimal($this->collect['amount'] ?? null)) {
            return false;
        }
        $total = Money::zero();
        foreach ($this->collect['tenders'] ?? [] as $tender) {
            if (! self::isExactMoneyDecimal($tender['amount'] ?? null)) {
                return false;
            }
            $total = $total->add(Money::fromDecimal($tender['amount']));
        }

        return $total->equals(Money::fromDecimal($this->collect['amount']));
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
        $state = [];
        $studentId = filter_var(request()->query('student'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $batchId = filter_var(request()->query('batch'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($studentId !== false && ($student = Student::query()->find($studentId)) !== null
            && Gate::allows('view', $student)) {
            $state['student_id'] = $studentId;
        }
        if ($batchId !== false && ($batch = Batch::query()->open()->find($batchId)) !== null
            && Gate::allows('view', $batch)) {
            $state['batch_id'] = $batchId;
        }
        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->studentStep(),
                $this->batchStep(),
                $this->discountStep(),
            ])
            ->gap(false)
            ->statePath('data');
    }

    public function advance(): void
    {
        if ($this->collectionChargeId !== null) {
            return;
        }

        if ($this->flowStep === 1 || $this->flowStep === 2) {
            $field = $this->flowStep === 1 ? 'data.student_id' : 'data.batch_id';
            $this->validateOnly($field, $this->form->getValidationRules(),
                $this->form->getValidationMessages(), $this->form->getValidationAttributes());
        }

        $this->flowStep = match ($this->flowStep) {
            1 => 2,
            2 => auth()->user()?->can('apply_discount') ? 3 : 4,
            default => 4,
        };
    }

    public function back(): void
    {
        $previous = $this->flowStep === 4 && ! auth()->user()?->can('apply_discount') ? 2 : $this->flowStep - 1;
        $this->goToStep($previous);
    }

    public function goToStep(int $step): void
    {
        if ($this->collectionChargeId !== null || $step < 1 || $step >= $this->flowStep
            || ($step === 3 && ! auth()->user()?->can('apply_discount'))) {
            return;
        }

        $this->flowStep = $step;
        $this->resetValidation();
    }

    /** @return Collection<int, Student> */
    #[Computed]
    public function studentMatches(): Collection
    {
        return Student::query()
            ->whereKey(array_keys(self::searchStudents($this->studentSearch)))
            ->orderBy('last_name')->orderBy('first_name')->get();
    }

    /** @return Collection<int, Batch> */
    #[Computed]
    public function batchChoices(): Collection
    {
        $batches = Batch::query()->with('course')
            ->whereKey(array_keys(self::searchBatches($this->batchSearch)))
            ->orderBy('code')->get();
        $seatCounts = app(EnrollmentQueryService::class)->activeSeatCountsForBatches($batches->modelKeys());

        foreach ($batches as $batch) {
            $batch->setAttribute(Batch::ACTIVE_ENROLLMENTS_COUNT, $seatCounts[$batch->getKey()] ?? 0);
        }

        return $batches;
    }

    public function batchPrice(Batch $batch): Money
    {
        return app(PricingService::class)->priceForBatch($batch);
    }

    /** @return array<int, array{id: int, label: string, amount: ?Money}> */
    #[Computed]
    public function discountChoices(): array
    {
        if (! auth()->user()?->can('apply_discount')) {
            return [];
        }

        $price = self::previewListPrice($this->data['batch_id'] ?? null);

        return Discount::query()->select(['id', 'name', 'percentage'])
            ->whereKey(array_keys(self::searchDiscounts($this->discountSearch)))
            ->orderBy('name')->get()
            ->map(fn (Discount $discount): array => [
                'id' => (int) $discount->getKey(),
                'label' => self::discountLabel($discount),
                'amount' => $price?->afterDiscount($discount->percentage),
            ])->all();
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
     * walk-in. The insert goes through CreateWithIdentifierCodeAction, the
     * same boundary CreateStudent uses (P35-T09): a blank code is generated
     * there, and a typed one stands. This page used to call
     * `Student::create()` directly on the grounds that students had no
     * write boundary to bypass; generated codes gave them one.
     */
    private function studentStep(): Group
    {
        return Group::make()
            ->schema([
                Select::make('student_id')
                    ->view('filament.finance.enroll-and-collect', ['picker' => 'student'])
                    ->label(__('collect.student'))
                    ->required()
                    ->live()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => self::searchStudents($search))
                    // Redisplaying an already-chosen value must not re-run
                    // the search; without this the field renders blank
                    // after a validation failure on the next step.
                    ->getOptionLabelUsing(fn (mixed $value): ?string => self::studentOptionLabel($value))
                    /*
                     * Quick-create is offered only to an actor who may create
                     * a student. `create_student` and `create_enrollment` are
                     * independent abilities (`StudentPolicy::create()`), and
                     * this page is gated on the second alone — so an operator
                     * granted enrolment but not student creation would
                     * otherwise be handed a create form they may not use.
                     *
                     * Hiding it is the courtesy; the refusal is the create
                     * authorization inside CreateWithIdentifierCodeAction,
                     * which `createStudent()` calls before anything is
                     * written, and a bespoke-role test drives both halves.
                     */
                    ->createOptionForm(fn (): ?array => auth()->user()?->can('create', Student::class)
                        ? [
                            TextInput::make('student_code')
                                ->label(__('collect.student_code'))
                                // Blank is generated; the hint is the one
                                // StudentResource shows, from the same key.
                                ->placeholder(__('enrollment.identifier_code_hint'))
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
                        ]
                        : null)
                    ->createOptionUsing(fn (array $data, Schema $schema): int => self::createStudentFromQuickCreate($data, $schema))
                    ->createOptionAction(fn (Action $action): Action => $action->label(__('collect.new_student'))->button()->outlined()),
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
    private function batchStep(): Group
    {
        return Group::make()
            ->schema([
                Select::make('batch_id')
                    ->view('filament.finance.enroll-and-collect', ['picker' => 'batch'])
                    ->label(__('collect.batch'))
                    ->required()
                    ->live()
                    ->options(fn (): array => self::searchBatches(''))
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => self::searchBatches($search))
                    ->getOptionLabelUsing(fn (mixed $value): ?string => self::batchOptionLabel($value)),
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
    private function discountStep(): Group
    {
        return Group::make()
            ->visible(fn (): bool => auth()->user()?->can('apply_discount') ?? false)
            ->schema([
                Select::make('discount_id')
                    ->view('filament.finance.enroll-and-collect', ['picker' => 'discount'])
                    ->label(__('collect.discount'))
                    ->options(fn (): array => self::searchDiscounts($this->discountSearch))
                    ->searchable()
                    ->live()
                    ->getSearchResultsUsing(fn (string $search): array => self::searchDiscounts($search))
                    ->getOptionLabelUsing(fn (mixed $value): ?string => self::discountOptionLabel($value)),
            ]);
    }

    /**
     * EnrollAndBillAction alone creates the enrolment and its bill.
     *
     * Validate student and batch through their schema. Preserve the RAW discount
     * id: dehydration prunes hidden fields, which would otherwise turn a crafted
     * unauthorized discount into a silently accepted full-price enrolment.
     * The Action is responsible for the authorization refusal.
     */
    public function confirm(): void
    {
        if ($this->collectionChargeId !== null) {
            return;
        }

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
        $this->flowStep = $this->collectionChargeId === null ? 1 : 4;
        if ($this->collectionChargeId === null) {
            $this->studentSearch = '';
            $this->batchSearch = '';
            $this->discountSearch = '';
        }
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
     * Separate collection state survives the selection-form reset.
     *
     * Money stays decimal text; numeric() would install Filament's float cast.
     * Card references are required only for card tenders, and a form rule
     * checks the exact tender sum before the Action enforces the same invariant
     * under its charge lock. Plain initial repeater keys allow deterministic
     * validation paths; added rows receive Filament's generated keys.
     */
    public function collectForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('amount')
                    ->label(__('collect.collect_amount'))
                    ->extraFieldWrapperAttributes(['class' => 'rounded-xl border border-primary-500/30 bg-primary-500/8 px-4 py-3.5 [&_.fi-input.fi-input]:text-[28px]!'])
                    ->extraInputAttributes(['class' => 'text-[28px]! font-semibold tracking-[-0.02em] tabular-nums'])
                    ->helperText(__('collect.collect_amount_hint'))
                    ->required()
                    ->inputMode('decimal')
                    ->suffix(__('payroll.currency'))
                    ->live(onBlur: true)
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
                            ->live(onBlur: true)
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
                    ->extraAttributes(['class' => '[&_.fi-fo-repeater-item]:rounded-[11px] [&_.fi-fo-repeater-item]:bg-fill-subtle [&_.fi-fo-repeater-item]:ring-hairline-faint'])
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
     * RecordPaymentAction is the only payment writer. Bare Livewire dispatch
     * keeps the same submission callable twice, so its stable key protects
     * retries and double-clicks rather than relying on an Action unmount.
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
                ->title(__('collect.installment_conflict'))
                ->danger()
                ->persistent()
                ->send();

            return;
        } catch (PaymentExceedsOutstandingException $exception) {
            Notification::make()
                ->title($exception->getMessage())
                ->body(__('collect.payment_exceeds_outstanding_detail', [
                    'attempted' => self::formatMoney($exception->attemptedAmount),
                    'outstanding' => self::formatMoney($exception->outstandingAmount),
                ]))
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('collect.collected_successfully'))
            ->success()
            ->send();
    }

    /** Finalize the optional collection through the bare Livewire method. */
    private function collectAction(): Action
    {
        return Action::make('collect')
            ->label(__('collect.collect_action'))
            ->action('finalize');
    }

    /** Finish with or without payment, without validating unsubmitted tenders. */
    private function doneAction(): Action
    {
        return Action::make('done')
            ->label(__('collect.done'))
            ->color('gray')
            ->action('finishCollection');
    }

    /**
     * Explicitly close this collection session and reset the next walk-in.
     * Successful finalize() retains the key until this distinct operator action.
     */
    public function finishCollection(): void
    {
        $this->collectionChargeId = null;
        $this->collectionIdempotencyKey = null;
        $this->collectForm->fill();
        $this->form->fill();
        $this->studentSearch = '';
        $this->batchSearch = '';
        $this->discountSearch = '';
        $this->flowStep = 1;
        $this->resetValidation();
    }

    /**
     * The batch's list price for the preview, or null before one is chosen.
     *
     * PUBLIC AND STATIC for the same testability reason as searchStudents()
     * below: this is the same price lookup the live bill uses, so testing
     * it directly tests the real behaviour. Delegates to
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
     * The live bill, discount cards and IssueChargeAction use the same
     * Money primitive, so their rounding cannot disagree by a dirham.
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
        $discount = Discount::query()
            ->select(['id', 'name', 'percentage'])
            ->find($value);

        return $discount instanceof Discount ? self::discountLabel($discount) : null;
    }

    /** @return array<int, string> */
    public static function searchDiscounts(string $search): array
    {
        return Discount::query()
            ->select(['id', 'name', 'percentage'])
            ->active()
            ->where('name', 'like', "%{$search}%")
            ->orderBy('name')
            ->limit(self::SEARCH_RESULT_LIMIT)
            ->get()
            ->mapWithKeys(fn (Discount $discount): array => [
                (int) $discount->getKey() => self::discountLabel($discount),
            ])
            ->all();
    }

    /** @return array<int, string> */
    public static function searchBatches(string $search): array
    {
        return Batch::query()
            ->open()
            ->join('courses', 'courses.id', '=', 'batches.course_id')
            ->select(['batches.id', 'batches.code', 'courses.code as course_code'])
            ->where(fn (Builder $query): Builder => $query
                ->where('batches.code', 'like', "%{$search}%")
                ->orWhere('courses.code', 'like', "%{$search}%"))
            ->orderBy('batches.code')
            ->limit(self::SEARCH_RESULT_LIMIT)
            ->get()
            ->mapWithKeys(fn (Batch $batch): array => [
                (int) $batch->getKey() => self::batchLabel($batch),
            ])
            ->all();
    }

    public static function batchOptionLabel(mixed $value): ?string
    {
        $batch = Batch::query()
            ->join('courses', 'courses.id', '=', 'batches.course_id')
            ->select(['batches.id', 'batches.code', 'courses.code as course_code'])
            ->find($value);

        return $batch instanceof Batch ? self::batchLabel($batch) : null;
    }

    private static function batchLabel(Batch $batch): string
    {
        return __('collect.batch_option', [
            'code' => $batch->code,
            'course' => $batch->getAttribute('course_code'),
        ]);
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
     * above. It is the body of what `createOptionUsing()` registers, less one
     * thing: createStudentFromQuickCreate() wraps it to put a code refusal on
     * the modal's own field. Calling this directly tests the write; the
     * wrapper and the modal's field rules are tested through the real modal
     * in IdentifierCodeTest.
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
        /** @var User $actor */
        $actor = auth()->user();

        /*
         * `create_student` IS ITS OWN ABILITY, AND IT IS STILL AUTHORIZED.
         *
         * `canAccess()` gates this page on `create_enrollment`, and
         * `StudentPolicy::create()` gates student creation on
         * `create_student` — deliberately independent abilities. This method
         * once wrote `students` directly and carried its own Gate check, found
         * by cross-review; the Action below now authorizes `create` for the
         * actor itself, before anything is written, so the refusal did not
         * move — it went with the write.
         *
         * The picker above hides quick-create for such an actor, but that is
         * a courtesy: the Action is the refusal.
         */
        $student = app(CreateWithIdentifierCodeAction::class)->createStudent($actor, [
            ...$data,
            'status' => StudentStatus::Prospective,
        ]);

        return (int) $student->getKey();
    }

    /**
     * createStudent(), with a code refusal landing on the modal's own field.
     *
     * createStudent() stays the plain, directly testable closure body and
     * throws the typed exceptions; this is the one place that knows where the
     * quick-create form lives. The shared translated message is the Action's.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException when the typed code is taken or generation is exhausted.
     */
    private static function createStudentFromQuickCreate(array $data, Schema $schema): int
    {
        try {
            return self::createStudent($data);
        } catch (IdentifierCodeAlreadyUsedException|IdentifierCodeExhaustedException $exception) {
            throw ValidationException::withMessages([
                $schema->getStatePath().'.student_code' => $exception->getMessage(),
            ]);
        }
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
