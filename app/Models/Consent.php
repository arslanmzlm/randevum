<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Consent extends Model
{
    use BelongsToClinic;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'clinic_id',
        'legal_document_id',
        'consentable_type',
        'consentable_id',
        'accepted_at',
        'ip_address',
        'user_agent',
        'accepted_by_user_id',
        'revoked_at',
        'revoked_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clinic_id' => 'integer',
            'legal_document_id' => 'integer',
            'consentable_id' => 'integer',
            'accepted_by_user_id' => 'integer',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function consentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<LegalDocument, $this>
     */
    public function legalDocument(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class);
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
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }
}
