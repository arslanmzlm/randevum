<?php

namespace App\Models;

use App\Enums\Gender;
use App\Models\Concerns\BelongsToClinic;
use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Propaganistas\LaravelPhone\Casts\E164PhoneNumberCast;

class Patient extends Model
{
    /** @use HasFactory<PatientFactory> */
    use BelongsToClinic, HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'clinic_id',
        'user_id',
        'first_name',
        'last_name',
        'phone',
        'contact_phone',
        'email',
        'birth_date',
        'gender',
        'notification_enabled',
        'is_legacy',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clinic_id' => 'integer',
            'user_id' => 'integer',
            'phone' => E164PhoneNumberCast::class.':TR',
            'contact_phone' => E164PhoneNumberCast::class.':TR',
            'birth_date' => 'date',
            'gender' => Gender::class,
            'notification_enabled' => 'boolean',
            'is_legacy' => 'boolean',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphMany<Consent, $this>
     */
    public function consents(): MorphMany
    {
        return $this->morphMany(Consent::class, 'consentable');
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'patient_tag')->withTimestamps();
    }

    /**
     * @return HasMany<Treatment, $this>
     */
    public function treatments(): HasMany
    {
        return $this->hasMany(Treatment::class);
    }
}
