<?php

namespace App\Models;

use App\Enums\TreatmentStatus;
use App\Models\Concerns\BelongsToClinic;
use Database\Factories\TreatmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Deliberately does NOT use the HasImageUrls trait: treatment media is medical
 * data and must never expose a public getUrl() — access is only via the
 * authorized streaming route (TreatmentMediaController).
 */
class Treatment extends Model implements HasMedia
{
    /** @use HasFactory<TreatmentFactory> */
    use BelongsToClinic, HasFactory, InteractsWithMedia;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'clinic_id',
        'appointment_id',
        'patient_id',
        'doctor_id',
        'case_id',
        'complaint',
        'diagnosis',
        'treatment_process',
        'details_type',
        'details_id',
        'subtotal_amount',
        'discount_amount',
        'total_amount',
        'notes',
        'status',
        'completed_at',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clinic_id' => 'integer',
            'appointment_id' => 'integer',
            'patient_id' => 'integer',
            'doctor_id' => 'integer',
            'case_id' => 'integer',
            'details_id' => 'integer',
            'subtotal_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'status' => TreatmentStatus::class,
            'completed_at' => 'datetime',
            'created_by' => 'integer',
            'updated_by' => 'integer',
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
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
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
     * @return BelongsTo<CaseRecord, $this>
     */
    public function case(): BelongsTo
    {
        return $this->belongsTo(CaseRecord::class, 'case_id');
    }

    /**
     * Optional vertical-specific extra fields. Null for a vertical that needs none —
     * the clinical trio (complaint/diagnosis/treatment_process) lives on this table.
     *
     * @return MorphTo<Model, $this>
     */
    public function details(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<TreatmentServiceLine, $this>
     */
    public function serviceLines(): HasMany
    {
        return $this->hasMany(TreatmentServiceLine::class);
    }

    /**
     * @return HasMany<TreatmentProductLine, $this>
     */
    public function productLines(): HasMany
    {
        return $this->hasMany(TreatmentProductLine::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return MorphMany<StatusLog, $this>
     */
    public function statusLogs(): MorphMany
    {
        return $this->morphMany(StatusLog::class, 'loggable');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @param  Builder<Treatment>  $query
     */
    public function scopeForDoctor(Builder $query, int $doctorId): void
    {
        $query->where('doctor_id', $doctorId);
    }

    /**
     * Multi-file — no singleFile(), unlike Clinic/Doctor's single-image collections.
     * Accepted MIME types are defense-in-depth; the FormRequest is the primary gate.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('treatment_media')
            ->useDisk('media_private')
            ->acceptsMimeTypes([
                'image/jpeg',
                'image/png',
                'image/webp',
                'image/heic',
                'image/heif',
                'application/pdf',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
    }

    /**
     * Images only (documents get no conversions — download-only, no docx→pdf per brief).
     * Width-only (no crop) since foot photos/before-after shots have no fixed aspect ratio.
     * Conversions run queued + non-fatal: a failure never blocks the original upload.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        if ($media === null || ! str_starts_with((string) $media->mime_type, 'image/')) {
            return;
        }

        foreach (['large' => 1920, 'medium' => 800, 'thumb' => 300] as $name => $width) {
            $this->addMediaConversion($name)
                ->width($width)
                ->format('webp')
                ->quality(85)
                ->performOnCollections('treatment_media')
                ->queued();
        }
    }
}
