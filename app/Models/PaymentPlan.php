<?php

namespace App\Models;

use App\Enums\InstallmentStatus;
use App\Enums\PaymentPlanStatus;
use App\Models\Concerns\BelongsToClinic;
use Database\Factories\PaymentPlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class PaymentPlan extends Model
{
    /** @use HasFactory<PaymentPlanFactory> */
    use BelongsToClinic, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'clinic_id',
        'patient_id',
        'treatment_id',
        'total_amount',
        'down_payment',
        'installment_count',
        'status',
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
            'created_by' => 'integer',
            'total_amount' => 'decimal:2',
            'down_payment' => 'decimal:2',
            'installment_count' => 'integer',
            'status' => PaymentPlanStatus::class,
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
     * Null for a patient general-balance plan (not tied to one treatment).
     *
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
     * @return HasMany<PaymentPlanInstallment, $this>
     */
    public function installments(): HasMany
    {
        return $this->hasMany(PaymentPlanInstallment::class)->orderBy('sequence');
    }

    /**
     * Whether any money has been taken against this plan — a paid installment or a transaction
     * pointing at one. Such a plan may be cancelled but never deleted: the payment records would
     * lose the schedule they belong to.
     */
    public function hasCollectedInstallments(): bool
    {
        return $this->installments()
            ->where(fn ($query) => $query
                ->where('status', InstallmentStatus::Paid->value)
                ->orWhereHas('transactions'))
            ->exists();
    }

    /**
     * @return MorphMany<StatusLog, $this>
     */
    public function statusLogs(): MorphMany
    {
        return $this->morphMany(StatusLog::class, 'loggable');
    }
}
