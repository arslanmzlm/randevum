<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use BelongsToClinic, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'clinic_id',
        'name',
        'color',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clinic_id' => 'integer',
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
     * @return BelongsToMany<Patient, $this>
     */
    public function patients(): BelongsToMany
    {
        return $this->belongsToMany(Patient::class, 'patient_tag')->withTimestamps();
    }
}
