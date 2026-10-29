<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Finance\Actions\AdjustChargeAction;
use App\Domain\Finance\Actions\CreateDiscountAction;
use App\Domain\Finance\Actions\CreateExpenseCategoryAction;
use App\Domain\Finance\Actions\DeactivateDiscountAction;
use App\Domain\Finance\Actions\RecordExpenseAction;
use App\Domain\Finance\Actions\RecordPaymentAction;
use App\Domain\Finance\Actions\ReverseExpenseAction;
use App\Domain\Finance\Actions\ReversePaymentAction;
use App\Domain\Finance\Actions\WriteOffChargeAction;
use App\Domain\Finance\Data\AdjustChargeData;
use App\Domain\Finance\Data\RecordPaymentData;
use App\Domain\Finance\Data\TenderData;
use App\Domain\Finance\Data\WriteOffChargeData;
use App\Domain\Finance\Enums\TenderMethod;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Support\ChargeBalance;
use App\Domain\Finance\Support\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The money: discounts, collections, corrections, and what the centre spends.
 *
 * No row here is written directly. Discounts, charges' corrections, write-offs,
 * payments and their reversals, expense categories and expenses each go through the
 * Action the screens call. Who may do what is the application's own rule, so the
 * actors are the accounts that really hold the permission:
 *
 * - the owner (super admin): pricing and discounts, adjusting and writing off a bill,
 *   reversing a payment or an expense, and about a third of the collections;
 * - the admin: the other collections, the expense categories and every expense.
 *
 * The front-desk accounts hold neither create_payment nor any other finance ability, by
 * design (RolePermissionSeeder), so they enrol walk-ins and issue portal logins but never
 * appear as the person who took money.
 *
 * A payment plan is replayed as the desk would have lived it: each step is recorded on
 * its own day through RecordPaymentAction, the same call the Collect payment button
 * makes, so a later installment is a new payment on the same bill with its own receipt.
 */
final class DemoFinanceSeeder extends DemoAreaSeeder
{
    /** Every fourth receipt is issued with the Arabic locale, as a desk with Arabic-speaking staff would. */
    private const ARABIC_RECEIPT_EVERY = 4;

    /** @var array<string, array{string, string}> name => [name, percentage] */
    private const DISCOUNTS = [
        'early' => ['Early registration', '10.00'],
        'family' => ['Family and friends', '15.00'],
        'promo' => ['Spring promotion', '20.00'],
    ];

    /** @var array<string, array{string, string}> key => [English, Arabic] */
    private const EXPENSE_CATEGORIES = [
        'rent' => ['Rent', 'الإيجار'],
        'utilities' => ['Utilities', 'المرافق'],
        'supplies' => ['Teaching supplies', 'مستلزمات التدريس'],
        'marketing' => ['Marketing', 'التسويق'],
    ];

    private int $payments = 0;

    /** @var array<string, Expense> */
    private array $expenses = [];

    public function run(): void
    {
        $this->registerDiscounts();
        $this->registerExpenseCategories();

        foreach (DemoScenarios::enrolments() as $row) {
            $this->registerBill($row);
        }

        $this->registerExpenses();
    }

    private function registerDiscounts(): void
    {
        $context = $this->context;

        $context->timeline->add($context->day(-183), 9, 'discounts', function () use ($context): void {
            foreach (self::DISCOUNTS as $key => [$name, $percentage]) {
                $context->discounts[$key] = app(CreateDiscountAction::class)->execute($context->user('owner'), $name, $percentage);
            }
        });

        // The spring promotion ended after it had been used; retiring it keeps the bills it priced.
        $context->timeline->add($context->day(-40), 9, 'retire the spring promotion', function () use ($context): void {
            app(DeactivateDiscountAction::class)->execute($context->user('owner'), $context->discounts['promo']);
        });
    }

    private function registerExpenseCategories(): void
    {
        $context = $this->context;

        $context->timeline->add($context->day(-183), 10, 'expense categories', function () use ($context): void {
            foreach (self::EXPENSE_CATEGORIES as $key => [$english, $arabic]) {
                $context->expenseCategories[$key] = app(CreateExpenseCategoryAction::class)->execute($context->user('admin'), $english, $arabic);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function registerBill(array $row): void
    {
        $context = $this->context;
        $id = "{$row['student']}:{$row['batch']}";
        $enrolDay = DemoScenarios::batches()[$row['batch']]['start'] + $row['offset'];

        if ($row['adjust'] !== null) {
            $adjust = $row['adjust'];

            $context->timeline->add($context->day($enrolDay + $adjust['after']), 10, "adjust bill {$id}", function () use ($context, $id, $adjust): void {
                app(AdjustChargeAction::class)->execute(
                    $context->user('owner'),
                    new AdjustChargeData($this->chargeFor($id), $adjust['to'], $adjust['reason']),
                );
            });
        }

        foreach ($this->steps($row['pay']) as $index => [$share, $days, $tender]) {
            if ($enrolDay + $days > 0) {
                continue;
            }

            $number = ++$this->payments;
            $key = "demo-{$id}-".($index + 1);

            $context->timeline->add($context->day($enrolDay + $days), 11, "payment {$key}", function () use ($id, $share, $tender, $number, $key): void {
                $this->collect($id, $share, $tender, $number, $key);
            });
        }

        if ($row['writeoff'] !== null) {
            $writeoff = $row['writeoff'];

            $context->timeline->add($context->day($enrolDay + $writeoff['after']), 11, "write off bill {$id}", function () use ($context, $id, $writeoff): void {
                app(WriteOffChargeAction::class)->execute(
                    $context->user('owner'),
                    new WriteOffChargeData($this->chargeFor($id), $writeoff['reason']),
                );
            });
        }

        if ($row['reverse'] !== null) {
            $reverse = $row['reverse'];

            $context->timeline->add($context->day($enrolDay + $reverse['after']), 12, "reverse payment on {$id}", function () use ($context, $id, $reverse): void {
                $paymentId = (int) Payment::query()
                    ->where('idempotency_key', "demo-{$id}-{$reverse['payment']}")
                    ->value('id');

                app(ReversePaymentAction::class)->execute($context->user('owner'), $paymentId, $reverse['reason']);
            });
        }
    }

    /**
     * @return list<array{string, int, string}> [share, days after enrolment, tender]
     */
    private function steps(string $plan): array
    {
        if ($plan === 'none') {
            return [];
        }

        return array_map(static function (string $step): array {
            if (preg_match('/^(\d+|rest)@(\d+):([a-z+]+)$/', $step, $parts) !== 1) {
                throw new InvalidArgumentException("The demo payment step [{$step}] is not share@days:tender.");
            }

            return [$parts[1], (int) $parts[2], $parts[3]];
        }, explode(' ', $plan));
    }

    private function chargeFor(string $id): int
    {
        return (int) Charge::query()
            ->where('enrollment_id', $this->context->enrollments[$id]->getKey())
            ->value('id');
    }

    private function collect(string $id, string $share, string $tender, int $number, string $key): void
    {
        $context = $this->context;
        $chargeId = $this->chargeFor($id);

        $billed = Money::fromDecimal((string) Charge::query()->whereKey($chargeId)->value('amount'));
        $owed = ChargeBalance::outstandingFor($chargeId);

        if (! $owed->isPositive()) {
            return;
        }

        $amount = $share === 'rest' ? $owed : $billed->multipliedBy((int) $share, 100);

        if ($amount->isGreaterThan($owed)) {
            $amount = $owed;
        }

        $actor = $context->user($number % 3 === 0 ? 'owner' : 'admin');
        $locale = app()->getLocale();

        if ($number % self::ARABIC_RECEIPT_EVERY === 0) {
            app()->setLocale('ar');
        }

        try {
            // collectableOnly is what the Collect payment panel sets (EnrollAndCollect.php:1085).
            app(RecordPaymentAction::class)->execute($actor, new RecordPaymentData(
                chargeId: $chargeId,
                allocation: $amount->toDecimal(),
                tenders: $this->tenders($tender, $amount, $number),
                idempotencyKey: $key,
                collectableOnly: true,
            ));
        } finally {
            app()->setLocale($locale);
        }
    }

    /**
     * @return list<TenderData>
     */
    private function tenders(string $spec, Money $amount, int $number): array
    {
        $methods = explode('+', $spec);

        if (count($methods) === 1) {
            return [$this->tender($methods[0], $amount, $number)];
        }

        $first = $amount->multipliedBy(1, 2);

        return [
            $this->tender($methods[0], $first, $number),
            $this->tender($methods[1], $amount->subtract($first), $number),
        ];
    }

    private function tender(string $method, Money $amount, int $number): TenderData
    {
        return match ($method) {
            'cash' => new TenderData(TenderMethod::Cash, $amount->toDecimal()),
            'card' => new TenderData(TenderMethod::Card, $amount->toDecimal(), sprintf('POS-DEMO-%04d', $number)),
            'bank' => new TenderData(TenderMethod::BankTransfer, $amount->toDecimal(), sprintf('BT-DEMO-%04d', $number)),
            'other' => new TenderData(TenderMethod::Other, $amount->toDecimal(), sprintf('CHQ-DEMO-%04d', $number)),
            default => throw new InvalidArgumentException("The demo tender [{$method}] is not cash, card, bank or other."),
        };
    }

    /**
     * Six months of running costs, one of them entered twice and reversed.
     */
    private function registerExpenses(): void
    {
        $utilities = ['420.000', '395.500', '410.250', '455.000', '480.750', '440.000'];

        $supplies = [
            -5 => [10, '180.000', 'Whiteboard markers and printer paper'],
            -3 => [12, '95.250', 'Printer toner'],
            -2 => [8, '310.000', 'Course workbooks for the new intakes'],
            -1 => [15, '150.500', 'Cleaning and kitchen supplies'],
            0 => [3, '75.000', 'Stationery'],
        ];

        $marketing = [
            -4 => [20, '250.000', 'Social media campaign'],
            -2 => [20, '250.000', 'Social media campaign'],
            -1 => [10, '400.000', 'Printed flyers and banners'],
        ];

        foreach (range(-5, 0) as $position => $month) {
            $first = $this->context->monthStart($month);

            $this->registerExpense("rent-{$month}", 'rent', '2500.000', $first, 'Premises rent for '.$first->format('F Y'));
            $this->registerExpense("utilities-{$month}", 'utilities', $utilities[$position], $first->addDays(4), 'Electricity, water and internet for '.$first->format('F Y'));

            if (isset($supplies[$month])) {
                [$day, $amount, $description] = $supplies[$month];
                $this->registerExpense("supplies-{$month}", 'supplies', $amount, $first->addDays($day - 1), $description);
            }

            if (isset($marketing[$month])) {
                [$day, $amount, $description] = $marketing[$month];
                $this->registerExpense("marketing-{$month}", 'marketing', $amount, $first->addDays($day - 1), $description);
            }
        }

        // The same campaign entered a second time on the same day, and taken back out.
        $campaign = $this->context->monthStart(-2)->addDays(19);
        $this->registerExpense('marketing-duplicate', 'marketing', '250.000', $campaign, 'Social media campaign');

        $context = $this->context;

        $context->timeline->add(min($campaign->addDay(), $context->today), 9, 'reverse the duplicated expense', function () use ($context): void {
            app(ReverseExpenseAction::class)->execute(
                $context->user('owner'),
                $this->expenses['marketing-duplicate'],
                'Entered twice by mistake; the first entry stands.',
            );
        });
    }

    private function registerExpense(string $key, string $category, string $amount, CarbonImmutable $paidOn, string $description): void
    {
        $context = $this->context;
        $paidOn = min($paidOn, $context->today);

        $context->timeline->add($paidOn, 12, "expense {$key}", function () use ($context, $key, $category, $amount, $paidOn, $description): void {
            $this->expenses[$key] = app(RecordExpenseAction::class)->execute(
                $context->user('admin'),
                (int) $context->expenseCategories[$category]->getKey(),
                $amount,
                $paidOn->toDateString(),
                $description,
            );
        });
    }
}
