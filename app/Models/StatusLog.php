<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StatusLog extends Model
{
    use BelongsToClinic;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'clinic_id',
        'loggable_type',
        'loggable_id',
        'from_status',
        'to_status',
        'transitioned_at',
        'by_user_id',
        'reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clinic_id' => 'integer',
            'loggable_id' => 'integer',
            'by_user_id' => 'integer',
            'transitioned_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function loggable(): MorphTo
    {
        return $this->morphTo();
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
    public function byUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'by_user_id');
    }
}
