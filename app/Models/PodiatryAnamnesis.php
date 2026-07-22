<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Vertical-specific anamnesis detail (morph target for patients.anamnesis).
 * No clinic scope — always reached through its owning Patient.
 */
class PodiatryAnamnesis extends Model
{
    protected $table = 'podiatry_anamneses';

    public const BLOOD_TYPES = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', '0+', '0-'];

    public const SMOKING = ['none', 'former', 'active'];

    public const ALCOHOL = ['none', 'occasional', 'regular'];

    public const DIABETES = ['type1', 'type2'];

    public const PREGNANCY = ['pregnant', 'breastfeeding'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'blood_type',
        'height_cm',
        'weight_kg',
        'smoking',
        'alcohol',
        'diabetes',
        'hypertension',
        'cardiovascular',
        'blood_thinners',
        'regular_medications',
        'other_chronic',
        'allergies',
        'pregnancy',
        'foot_surgery_history',
        'diabetic_foot_history',
        'current_foot_complaint',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'height_cm' => 'integer',
            'weight_kg' => 'decimal:2',
            'hypertension' => 'boolean',
            'cardiovascular' => 'boolean',
            'blood_thinners' => 'boolean',
            'diabetic_foot_history' => 'boolean',
        ];
    }
}
