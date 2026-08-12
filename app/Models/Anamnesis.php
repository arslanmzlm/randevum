<?php

namespace App\Models;

use App\Enums\AlcoholUse;
use App\Enums\BloodType;
use App\Enums\DiabetesStatus;
use App\Enums\PregnancyStatus;
use App\Enums\SmokingStatus;
use App\Models\Concerns\BelongsToClinic;
use Database\Factories\AnamnesisFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Single clinic-owned anamnesis row per patient. Core clinical columns are shared across
 * every vertical; `extra` carries vertical/clinic-specific answers keyed by
 * AnamnesisField::key (see AnamnesisFieldService).
 */
class Anamnesis extends Model
{
    /** @use HasFactory<AnamnesisFactory> */
    use BelongsToClinic, HasFactory;

    protected $table = 'anamneses';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'clinic_id',
        'patient_id',
        'blood_type',
        'height_cm',
        'weight_kg',
        'smoking',
        'alcohol',
        'diabetes',
        'hypertension',
        'cardiovascular',
        'respiratory',
        'kidney_liver',
        'thyroid',
        'epilepsy',
        'blood_thinners',
        'bleeding_disorder',
        'infectious_disease',
        'infectious_disease_note',
        'regular_medications',
        'other_chronic',
        'allergies',
        'surgery_history',
        'family_history',
        'pregnancy',
        'menstrual_notes',
        'extra',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clinic_id' => 'integer',
            'patient_id' => 'integer',
            'blood_type' => BloodType::class,
            'height_cm' => 'integer',
            'weight_kg' => 'decimal:2',
            'smoking' => SmokingStatus::class,
            'alcohol' => AlcoholUse::class,
            'diabetes' => DiabetesStatus::class,
            'pregnancy' => PregnancyStatus::class,
            'hypertension' => 'boolean',
            'cardiovascular' => 'boolean',
            'respiratory' => 'boolean',
            'kidney_liver' => 'boolean',
            'thyroid' => 'boolean',
            'epilepsy' => 'boolean',
            'blood_thinners' => 'boolean',
            'bleeding_disorder' => 'boolean',
            'infectious_disease' => 'boolean',
            'extra' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * Body mass index derived from height/weight — never stored. Null when either input
     * is missing. Single source of truth for the PDF and the AnamnesisResource prop; the
     * frontend recomputes it client-side for live feedback.
     */
    public function bmi(): ?float
    {
        if ($this->height_cm === null || $this->weight_kg === null) {
            return null;
        }

        $heightMeters = $this->height_cm / 100;

        return round((float) $this->weight_kg / ($heightMeters * $heightMeters), 1);
    }
}
