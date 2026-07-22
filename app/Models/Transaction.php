<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\TransactionStatus;
use App\Models\Concerns\BelongsToClinic;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use BelongsToClinic, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'clinic_id',
        'patient_id',
        'treatment_id',
        'original_transaction_id',
        'payment_plan_installment_id',
        'amount',
        'payment_method',
        'status',
        'paid_at',
        'note',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clinic_id' => 'integer',
            'patient_id' => 'integer',
            'treatment_id' => 'integer',
            'original_transaction_id' => 'integer',
            'payment_plan_installment_id' => 'integer',
            'amount' => 'decimal:2',
            'payment_method' => PaymentMethod::class,
            'status' => TransactionStatus::class,
            'paid_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Clinic, $this>
     */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<Treatment, $this>
     */
    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The payment this counter-entry reverses (null for normal payments).
     *
     * @return BelongsTo<Transaction, $this>
     */
    public function originalTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'original_transaction_id');
    }

    /**
     * Refund counter-entries that reverse this payment.
     *
     * @return HasMany<Transaction, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Transaction::class, 'original_transaction_id');
    }

    /**
     * The payment-plan installment this collection settles (null on a normal/split payment
     * or a down payment, which is never installment-linked).
     *
     * @return BelongsTo<PaymentPlanInstallment, $this>
     */
    public function installment(): BelongsTo
    {
        return $this->belongsTo(PaymentPlanInstallment::class, 'payment_plan_installment_id');
    }

    /**
     * @return MorphMany<StatusLog, $this>
     */
    public function statusLogs(): MorphMany
    {
        return $this->morphMany(StatusLog::class, 'loggable');
    }
}
