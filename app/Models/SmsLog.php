<?php

namespace App\Models;

use App\Enums\SmsStatus;
use App\Enums\SmsType;
use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SmsLog extends Model
{
    use BelongsToClinic;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'clinic_id',
        'patient_id',
        'phone',
        'type',
        'loggable_type',
        'loggable_id',
        'body',
        'status',
        'provider_ref',
        'scheduled_at',
        'sent_at',
        'error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clinic_id' => 'integer',
            'patient_id' => 'integer',
            'loggable_id' => 'integer',
            'type' => SmsType::class,
            'status' => SmsStatus::class,
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function loggable(): MorphTo
    {
        return $this->morphTo();
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
}
