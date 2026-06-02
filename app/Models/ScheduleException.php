<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Database\Factories\ScheduleExceptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ScheduleException extends Model
{
    /** @use HasFactory<ScheduleExceptionFactory> */
    use BelongsToClinic, HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'clinic_id',
        'doctor_id',
        'starts_at',
        'ends_at',
        'is_all_day',
        'reason',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clinic_id' => 'integer',
            'doctor_id' => 'integer',
            'created_by' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_all_day' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Doctor, $this>
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /**
     * @return BelongsTo<Clinic, $this>
     */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<ScheduleException>  $query
     */
    public function scopeForDoctor(Builder $query, int $doctorId): void
    {
        $query->where('doctor_id', $doctorId);
    }

    /**
     * Selects exceptions that overlap the given [start, end] range (inclusive).
     *
     * Used by the 1.18 conflict-check feature.
     *
     * @param  Builder<ScheduleException>  $query
     */
    public function scopeOverlapping(Builder $query, mixed $start, mixed $end): void
    {
        $query->where('starts_at', '<=', $end)->where('ends_at', '>=', $start);
    }
}
