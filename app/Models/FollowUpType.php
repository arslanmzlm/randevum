<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Database\Factories\FollowUpTypeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FollowUpType extends Model
{
    /** @use HasFactory<FollowUpTypeFactory> */
    use BelongsToClinic, HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'clinic_id',
        'name',
        'is_system',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clinic_id' => 'integer',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<FollowUpType>  $query
     * @return Builder<FollowUpType>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
