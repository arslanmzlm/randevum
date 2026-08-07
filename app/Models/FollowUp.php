<?php

namespace App\Models;

use App\Enums\FollowUpStatus;
use App\Models\Concerns\BelongsToClinic;
use Database\Factories\FollowUpFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class FollowUp extends Model
{
    /** @use HasFactory<FollowUpFactory> */
    use BelongsToClinic, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'clinic_id',
        'patient_id',
        'case_id',
        'follow_up_type_id',
        'due_date',
        'note',
        'status',
        'assigned_to_user_id',
        'created_by_user_id',
        'completed_by_user_id',
        'completed_at',
        'result_note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clinic_id' => 'integer',
            'patient_id' => 'integer',
            'case_id' => 'integer',
            'follow_up_type_id' => 'integer',
            'assigned_to_user_id' => 'integer',
            'created_by_user_id' => 'integer',
            'completed_by_user_id' => 'integer',
            'due_date' => 'date',
            'status' => FollowUpStatus::class,
            'completed_at' => 'datetime',
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
     * @return BelongsTo<CaseRecord, $this>
     */
    public function caseRecord(): BelongsTo
    {
        return $this->belongsTo(CaseRecord::class, 'case_id');
    }

    /**
     * @return BelongsTo<FollowUpType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(FollowUpType::class, 'follow_up_type_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    /**
     * @return MorphMany<StatusLog, $this>
     */
    public function statusLogs(): MorphMany
    {
        return $this->morphMany(StatusLog::class, 'loggable');
    }

    /**
     * @param  Builder<FollowUp>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', FollowUpStatus::Open->value);
    }

    /**
     * @param  Builder<FollowUp>  $query
     */
    public function scopeDueOn(Builder $query, string $date): void
    {
        $query->open()->whereDate('due_date', '<=', $date);
    }
}
