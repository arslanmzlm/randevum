<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Models\Concerns\BelongsToClinic;
use Carbon\CarbonInterface;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use BelongsToClinic, HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'clinic_id',
        'patient_id',
        'doctor_id',
        'case_id',
        'appointment_type_id',
        'service_id',
        'starts_at',
        'ends_at',
        'status',
        'is_walk_in',
        'reminder_24h_sent',
        'reminder_1h_sent',
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
            'doctor_id' => 'integer',
            'case_id' => 'integer',
            'appointment_type_id' => 'integer',
            'service_id' => 'integer',
            'created_by' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => AppointmentStatus::class,
            'is_walk_in' => 'boolean',
            'reminder_24h_sent' => 'boolean',
            'reminder_1h_sent' => 'boolean',
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
     * @return BelongsTo<AppointmentType, $this>
     */
    public function appointmentType(): BelongsTo
    {
        return $this->belongsTo(AppointmentType::class);
    }

    /**
     * The service the patient is booked for (visit intent). Nullable.
     *
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasOne<Treatment, $this>
     */
    public function treatment(): HasOne
    {
        return $this->hasOne(Treatment::class);
    }

    /**
     * @return MorphMany<StatusLog, $this>
     */
    public function statusLogs(): MorphMany
    {
        return $this->morphMany(StatusLog::class, 'loggable');
    }

    /**
     * @param  Builder<Appointment>  $query
     */
    public function scopeForDoctor(Builder $query, int $doctorId): void
    {
        $query->where('doctor_id', $doctorId);
    }

    /**
     * Strict overlap: back-to-back slots (ends_at == next starts_at) do not conflict.
     *
     * @param  Builder<Appointment>  $query
     */
    public function scopeOverlapping(Builder $query, mixed $start, mixed $end): void
    {
        $query->where('starts_at', '<', $end)->where('ends_at', '>', $start);
    }

    /**
     * @param  Builder<Appointment>  $query
     * @param  list<AppointmentStatus>  $statuses
     */
    public function scopeWithStatus(Builder $query, array $statuses): void
    {
        $query->whereIn('status', array_map(
            fn (AppointmentStatus $s) => $s->value,
            $statuses,
        ));
    }

    /**
     * Strict-overlap range filter: any appointment whose window overlaps [start, end).
     * Does NOT exclude Cancelled — apply scopeWithStatus separately for status filtering.
     *
     * @param  Builder<Appointment>  $query
     */
    public function scopeInRange(Builder $query, mixed $startUtc, mixed $endUtc): void
    {
        $query->where('starts_at', '<', $endUtc)->where('ends_at', '>', $startUtc);
    }

    /**
     * Appointments that overlap a calendar day window (UTC), excluding Cancelled.
     *
     * @param  Builder<Appointment>  $query
     */
    public function scopeForDay(Builder $query, CarbonInterface $dayStartUtc, CarbonInterface $dayEndUtc): void
    {
        $query->inRange($dayStartUtc, $dayEndUtc)
            ->where('status', '!=', AppointmentStatus::Cancelled->value);
    }

    /**
     * Appointments whose starts_at falls in [fromUtc, toUtc) — exclusive end.
     *
     * "Günü kapat" semantics: only appointments that START within the selected days
     * are in scope; a late-night visit from the night before is not affected.
     *
     * @param  Builder<Appointment>  $query
     */
    public function scopeStartingBetween(Builder $query, mixed $fromUtc, mixed $toUtc): void
    {
        $query->where('starts_at', '>=', $fromUtc)->where('starts_at', '<', $toUtc);
    }
}
