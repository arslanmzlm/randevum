<?php

namespace Database\Seeders;

use App\Enums\InstallmentStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentPlanStatus;
use App\Enums\TransactionStatus;
use App\Enums\TreatmentStatus;
use App\Models\Clinic;
use App\Models\Expense;
use App\Models\Patient;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Models\Transaction;
use App\Models\Treatment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * The money side of the demo clinic: payments against completed treatments (fully paid,
 * part-paid and untouched), a refund pair, standalone payments, expenses across categories and
 * months, and installment plans in every state. Without these the balance, finance and payment
 * plan screens are empty and every patient looks like a debtor.
 *
 * Runs AFTER DemoCasesSeeder (needs completed treatments to pay for).
 */
class DemoBillingSeeder extends Seeder
{
    private Clinic $clinic;

    private User $owner;

    public function run(): void
    {
        $clinic = Clinic::where('slug', 'podosen-izmir')->first();
        $owner = User::where('email', 'owner@podosen.test')->first();

        if (! $clinic || ! $owner) {
            return;
        }

        $this->clinic = $clinic;
        $this->owner = $owner;

        $this->seedTreatmentPayments();
        $this->seedRefund();
        $this->seedExpenses();
        $this->seedPaymentPlans();
        $this->seedManualIncome();
        $this->seedTodaysPayments();
    }

    /**
     * Manuel gelir: clinic income with no patient (patient_id NULL), spread over the current
     * month so the finance page's income breakdown is non-empty in the demo.
     */
    private function seedManualIncome(): void
    {
        if (Transaction::withoutGlobalScopes()->where('clinic_id', $this->clinic->id)->whereNull('patient_id')->exists()) {
            return;
        }

        $rows = [
            ['Kira geliri', PaymentMethod::Transfer, 4500, 3],
            ['Ürün toptan satışı', PaymentMethod::Cash, 1200, 10],
            ['Kurs geliri', PaymentMethod::Card, 2800, 17],
        ];

        foreach ($rows as [$category, $method, $amount, $dayOfMonth]) {
            Transaction::create([
                'clinic_id' => $this->clinic->id,
                'patient_id' => null,
                'treatment_id' => null,
                'amount' => $amount,
                'payment_method' => $method,
                'status' => TransactionStatus::Completed,
                'paid_at' => Carbon::today()->startOfMonth()->addDays($dayOfMonth - 1),
                'category' => $category,
                'created_by' => $this->owner->id,
            ]);
        }
    }

    /**
     * Day-relative top-up: the payment dates above are spread when the seeder runs, so a database
     * seeded even one day ago shows an empty "today's revenue" card. Two payments dated today keep
     * the dashboard and the revenue report's today bucket honest on every re-seed.
     */
    private function seedTodaysPayments(): void
    {
        $paidToday = Transaction::withoutGlobalScopes()
            ->where('clinic_id', $this->clinic->id)
            ->whereDate('paid_at', Carbon::today())
            ->where('amount', '>', 0)
            ->exists();

        if ($paidToday) {
            return;
        }

        $treatments = Treatment::withoutGlobalScopes()
            ->where('clinic_id', $this->clinic->id)
            ->where('status', TreatmentStatus::Completed)
            ->where('total_amount', '>', 0)
            ->orderByDesc('id')
            ->take(2)
            ->get();

        foreach ($treatments as $index => $treatment) {
            Transaction::create([
                'clinic_id' => $this->clinic->id,
                'patient_id' => $treatment->patient_id,
                'treatment_id' => $treatment->id,
                'amount' => bcdiv((string) $treatment->total_amount, '2', 2),
                'payment_method' => $index === 0 ? PaymentMethod::Cash : PaymentMethod::Card,
                'status' => TransactionStatus::Completed,
                'paid_at' => Carbon::today()->addHours(10 + $index * 3),
                'created_by' => $this->owner->id,
            ]);
        }
    }

    /**
     * Three payment shapes over the completed treatments so balance rows differ: settled in full,
     * part-paid (open balance), and unpaid. Paid dates follow the treatment, which spreads them
     * over the revenue report's day/month buckets.
     */
    private function seedTreatmentPayments(): void
    {
        $treatments = Treatment::withoutGlobalScopes()
            ->where('clinic_id', $this->clinic->id)
            ->where('status', TreatmentStatus::Completed)
            ->where('total_amount', '>', 0)
            ->orderBy('id')
            ->get();

        $methods = PaymentMethod::cases();

        foreach ($treatments as $index => $treatment) {
            $alreadyPaid = Transaction::withoutGlobalScopes()
                ->where('treatment_id', $treatment->id)
                ->exists();

            if ($alreadyPaid) {
                continue;
            }

            $share = match ($index % 5) {
                0, 1, 2 => '1.00',   // paid in full
                3 => '0.40',         // part-paid, open balance
                default => '0.00',   // untouched
            };

            if ($share === '0.00') {
                continue;
            }

            $amount = bcmul((string) $treatment->total_amount, $share, 2);

            // Payment dates ride a rolling 90-day window ending today (never earlier than the
            // treatment itself) so the revenue report's today / this-month buckets are populated
            // however long ago the treatments were seeded.
            $completedAt = Carbon::parse($treatment->completed_at ?? $treatment->created_at);
            $paidAt = Carbon::today()->subDays($index % 90)->setTimeFrom($completedAt);

            if ($paidAt->lessThan($completedAt)) {
                $paidAt = $completedAt;
            }

            // A part-payment is realistic as two instalments on different days.
            $splits = $share === '1.00' && $index % 3 === 0
                ? [bcdiv($amount, '2', 2), bcsub($amount, bcdiv($amount, '2', 2), 2)]
                : [$amount];

            foreach ($splits as $splitIndex => $splitAmount) {
                $splitPaidAt = $paidAt->copy()->addDays($splitIndex * 3);

                if ($splitPaidAt->isFuture()) {
                    $splitPaidAt = $paidAt->copy();
                }

                Transaction::create([
                    'clinic_id' => $this->clinic->id,
                    'patient_id' => $treatment->patient_id,
                    'treatment_id' => $treatment->id,
                    'amount' => $splitAmount,
                    'payment_method' => $methods[($index + $splitIndex) % count($methods)],
                    'status' => TransactionStatus::Completed,
                    'paid_at' => $splitPaidAt,
                    'note' => $splitIndex > 0 ? 'Kalan tutar' : null,
                    'created_by' => $this->owner->id,
                ]);
            }
        }
    }

    /**
     * One partially refunded payment: the counter-entry is a negative transaction linked to the
     * original, exactly as RefundService writes it, so the refund badge and the linked-pair UI
     * have a real case to show.
     */
    private function seedRefund(): void
    {
        $refundExists = Transaction::withoutGlobalScopes()
            ->where('clinic_id', $this->clinic->id)
            ->whereNotNull('original_transaction_id')
            ->exists();

        if ($refundExists) {
            return;
        }

        $original = Transaction::withoutGlobalScopes()
            ->where('clinic_id', $this->clinic->id)
            ->where('status', TransactionStatus::Completed)
            ->whereNull('original_transaction_id')
            ->where('amount', '>', 200)
            ->orderBy('id')
            ->first();

        if (! $original) {
            return;
        }

        $refundAmount = bcdiv((string) $original->amount, '2', 2);

        Transaction::create([
            'clinic_id' => $this->clinic->id,
            'patient_id' => $original->patient_id,
            'treatment_id' => $original->treatment_id,
            'original_transaction_id' => $original->id,
            'amount' => bcsub('0', $refundAmount, 2),
            'payment_method' => $original->payment_method,
            'status' => TransactionStatus::Refunded,
            'paid_at' => now()->subDays(2),
            'note' => 'Yarıda kalan tedavi için kısmi iade',
            'created_by' => $this->owner->id,
        ]);

        $original->forceFill(['status' => TransactionStatus::PartiallyRefunded])->save();
    }

    /**
     * Expenses across the finance page's categories and three months, split between the owner and
     * a staff member so the two-tier permission (own expenses vs clinic overview) is reviewable.
     */
    private function seedExpenses(): void
    {
        if (Expense::withoutGlobalScopes()->where('clinic_id', $this->clinic->id)->count() >= 30) {
            return;
        }

        $staff = User::where('email', 'reception@podosen.test')->first() ?? $this->owner;

        $rows = [
            ['Kira', 'Klinik kira ödemesi', 28000],
            ['Fatura', 'Elektrik faturası', 3450],
            ['Fatura', 'Su faturası', 780],
            ['Fatura', 'İnternet + telefon', 1250],
            ['Malzeme', 'Tek kullanımlık frez seti', 4200],
            ['Malzeme', 'Dezenfektan ve sarf malzeme', 2650],
            ['Malzeme', 'Silikon ortez hammaddesi', 5900],
            ['Personel', 'Asistan maaş ödemesi', 32000],
            ['Personel', 'SGK primleri', 9800],
            ['Diğer', 'Muhasebe hizmet bedeli', 3500],
            ['Diğer', 'Kargo ve posta giderleri', 620],
        ];

        foreach ([0, 1, 2] as $monthsAgo) {
            foreach ($rows as $index => [$category, $description, $amount]) {
                Expense::create([
                    'clinic_id' => $this->clinic->id,
                    'expense_date' => Carbon::today()
                        ->subMonths($monthsAgo)
                        ->startOfMonth()
                        ->addDays(($index * 2) % 26),
                    // Small month-to-month drift so the period comparison isn't three identical columns.
                    'amount' => $amount + ($monthsAgo * 175),
                    'category' => $category,
                    'description' => $description,
                    'created_by' => $index % 3 === 0 ? $staff->id : $this->owner->id,
                ]);
            }
        }
    }

    /**
     * Four plans covering the states the screens branch on: an active plan mid-way through, one
     * with an overdue installment, a completed plan and a cancelled one.
     */
    private function seedPaymentPlans(): void
    {
        if (PaymentPlan::withoutGlobalScopes()->where('clinic_id', $this->clinic->id)->count() >= 4) {
            return;
        }

        $patients = Patient::withoutGlobalScopes()
            ->where('clinic_id', $this->clinic->id)
            ->orderBy('id')
            ->take(8)
            ->get();

        if ($patients->count() < 4) {
            return;
        }

        // [status, total, count, downPayment, paidCount, firstDueOffsetDays]
        $plans = [
            [PaymentPlanStatus::Active, '6000.00', 6, '1000.00', 2, -60],
            [PaymentPlanStatus::Active, '4500.00', 3, null, 1, -45],
            [PaymentPlanStatus::Completed, '2400.00', 4, null, 4, -150],
            [PaymentPlanStatus::Cancelled, '3000.00', 3, null, 1, -90],
        ];

        foreach ($plans as $index => [$status, $total, $count, $downPayment, $paidCount, $firstDueOffset]) {
            $patient = $patients[$index];

            $plan = PaymentPlan::create([
                'clinic_id' => $this->clinic->id,
                'patient_id' => $patient->id,
                'treatment_id' => null,
                'total_amount' => $total,
                'down_payment' => $downPayment,
                'installment_count' => $count,
                'status' => $status,
                'created_by' => $this->owner->id,
            ]);

            $financed = bcsub($total, $downPayment ?? '0.00', 2);
            $perInstallment = bcdiv($financed, (string) $count, 2);

            if ($downPayment !== null) {
                Transaction::create([
                    'clinic_id' => $this->clinic->id,
                    'patient_id' => $patient->id,
                    'amount' => $downPayment,
                    'payment_method' => PaymentMethod::Card,
                    'status' => TransactionStatus::Completed,
                    'paid_at' => Carbon::today()->addDays($firstDueOffset)->subDay(),
                    'note' => 'Peşinat',
                    'created_by' => $this->owner->id,
                ]);
            }

            for ($sequence = 1; $sequence <= $count; $sequence++) {
                $dueDate = Carbon::today()->addDays($firstDueOffset)->addMonths($sequence - 1);
                $isPaid = $sequence <= $paidCount;

                // Last installment absorbs the rounding remainder.
                $amount = $sequence === $count
                    ? bcsub($financed, bcmul($perInstallment, (string) ($count - 1), 2), 2)
                    : $perInstallment;

                $installmentStatus = match (true) {
                    $isPaid => InstallmentStatus::Paid,
                    $status === PaymentPlanStatus::Cancelled => InstallmentStatus::Cancelled,
                    default => InstallmentStatus::Pending,
                };

                $installment = PaymentPlanInstallment::create([
                    'clinic_id' => $this->clinic->id,
                    'payment_plan_id' => $plan->id,
                    'sequence' => $sequence,
                    'due_date' => $dueDate,
                    'amount' => $amount,
                    'status' => $installmentStatus,
                    'paid_at' => $isPaid ? $dueDate->copy()->addDay() : null,
                    // A pending installment that is already past its due date drives the overdue badge.
                    'reminder_7d_sent' => ! $isPaid && $dueDate->isPast(),
                    'reminder_1d_sent' => ! $isPaid && $dueDate->isPast(),
                ]);

                if (! $isPaid) {
                    continue;
                }

                Transaction::create([
                    'clinic_id' => $this->clinic->id,
                    'patient_id' => $patient->id,
                    'payment_plan_installment_id' => $installment->id,
                    'amount' => $amount,
                    'payment_method' => PaymentMethod::Transfer,
                    'status' => TransactionStatus::Completed,
                    'paid_at' => $dueDate->copy()->addDay(),
                    'note' => $sequence.'. taksit',
                    'created_by' => $this->owner->id,
                ]);
            }
        }
    }
}
