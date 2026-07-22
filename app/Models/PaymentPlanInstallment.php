<?php

namespace App\Models;

use App\Enums\InstallmentStatus;
use App\Models\Concerns\BelongsToClinic;
use Database\Factories\PaymentPlanInstallmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class PaymentPlanInstallment extends Model
{
    /** @use HasFactory<PaymentPlanInstallmentFactory> */
    use BelongsToClinic, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'clinic_id',
        'payment_plan_id',
        'sequence',
        'due_date',
        'amount',
        'status',
        'paid_at',
        'reminder_7d_sent',
        'reminder_1d_sent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clinic_id' => 'integer',
            'payment_plan_id' => 'integer',
            'sequence' => 'integer',
            'due_date' => 'date',
            'amount' => 'decimal:2',
            'status' => InstallmentStatus::class,
            'paid_at' => 'datetime',
            'reminder_7d_sent' => 'boolean',
            'reminder_1d_sent' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<PaymentPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(PaymentPlan::class, 'payment_plan_id');
    }

    /**
     * @return BelongsTo<Clinic, $this>
     */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /**
     * Collections that settle this installment. Normally at most one (v1 is full-installment
     * collection only), but the relation stays HasMany like Transaction's own refund lines.
     *
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'payment_plan_installment_id');
    }

    /**
     * @return MorphMany<StatusLog, $this>
     */
    public function statusLogs(): MorphMany
    {
        return $this->morphMany(StatusLog::class, 'loggable');
    }
}
