<?php

namespace App\Models;

use App\Enums\CaseStatus;
use App\Models\Concerns\BelongsToClinic;
use Database\Factories\CaseRecordFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * `Case` is a PHP reserved word — this model uses the `cases` table.
 */
class CaseRecord extends Model
{
    /** @use HasFactory<CaseRecordFactory> */
    use BelongsToClinic, HasFactory;

    protected $table = 'cases';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'clinic_id',
        'patient_id',
        'doctor_id',
        'vertical_id',
        'title',
        'status',
        'notes',
        'opened_at',
        'closed_at',
        'suspended_at',
        'follow_up_date',
        'follow_up_note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clinic_id' => 'integer',
            'patient_id' => 'integer',
            'doctor_id' => 'integer',
            'vertical_id' => 'integer',
            'status' => CaseStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'suspended_at' => 'datetime',
            'follow_up_date' => 'date',
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
     * @return BelongsTo<Doctor, $this>
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /**
     * @return HasMany<Treatment, $this>
     */
    public function treatments(): HasMany
    {
        return $this->hasMany(Treatment::class, 'case_id');
    }

    /**
     * @return MorphMany<StatusLog, $this>
     */
    public function statusLogs(): MorphMany
    {
        return $this->morphMany(StatusLog::class, 'loggable');
    }

    /**
     * @param  Builder<CaseRecord>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', CaseStatus::Open->value);
    }

    /**
     * @param  Builder<CaseRecord>  $query
     */
    public function scopeForDoctor(Builder $query, int $doctorId): void
    {
        $query->where('doctor_id', $doctorId);
    }
}
