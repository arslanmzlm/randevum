<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Database\Factories\AppointmentTypeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AppointmentType extends Model
{
    /** @use HasFactory<AppointmentTypeFactory> */
    use BelongsToClinic, HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'clinic_id',
        'vertical_id',
        'name',
        'color',
        'default_duration_minutes',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clinic_id' => 'integer',
            'vertical_id' => 'integer',
            'default_duration_minutes' => 'integer',
            'is_active' => 'boolean',
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
     * @return BelongsTo<Vertical, $this>
     */
    public function vertical(): BelongsTo
    {
        return $this->belongsTo(Vertical::class);
    }

    /**
     * @param  Builder<AppointmentType>  $query
     * @return Builder<AppointmentType>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
