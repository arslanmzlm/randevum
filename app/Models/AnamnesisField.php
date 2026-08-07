<?php

namespace App\Models;

use App\Enums\AnamnesisFieldType;
use App\Models\Concerns\BelongsToClinic;
use Database\Factories\AnamnesisFieldFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Field definition driving the dynamic anamnesis form, its validation and the PDF.
 * Vertical-scoped rows (clinic_id null) are seed-managed this wave; clinic_id ships for
 * the Faz 3 per-clinic override — see the multi-tenancy guideline's platform-row
 * exception: repositories read these with withoutGlobalScopes() + an explicit predicate.
 */
class AnamnesisField extends Model
{
    /** @use HasFactory<AnamnesisFieldFactory> */
    use BelongsToClinic, HasFactory;

    protected $table = 'anamnesis_fields';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'vertical_id',
        'clinic_id',
        'key',
        'label',
        'group',
        'type',
        'options',
        'sort',
        'required',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vertical_id' => 'integer',
            'clinic_id' => 'integer',
            'type' => AnamnesisFieldType::class,
            'options' => 'array',
            'sort' => 'integer',
            'required' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
