<?php

namespace App\Models;

use Database\Factories\VerticalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vertical extends Model
{
    /** @use HasFactory<VerticalFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'config',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'config' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Clinic, $this>
     */
    public function clinics(): HasMany
    {
        return $this->hasMany(Clinic::class);
    }
}
