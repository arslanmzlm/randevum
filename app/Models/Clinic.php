<?php

namespace App\Models;

use Database\Factories\ClinicFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Propaganistas\LaravelPhone\Casts\E164PhoneNumberCast;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Clinic extends Model implements HasMedia
{
    /** @use HasFactory<ClinicFactory> */
    use HasFactory, InteractsWithMedia, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'vertical_id',
        'name',
        'slug',
        'description',
        'phone',
        'email',
        'website',
        'country_id',
        'city_id',
        'district',
        'address',
        'postal_code',
        'latitude',
        'longitude',
        'working_hours',
        'default_slot_duration_minutes',
        'timezone',
        'locale',
        'currency',
        'is_active',
        'onboarded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'vertical_id' => 'integer',
            'country_id' => 'integer',
            'city_id' => 'integer',
            'phone' => E164PhoneNumberCast::class.':TR',
            'working_hours' => 'array',
            'default_slot_duration_minutes' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_active' => 'boolean',
            'onboarded_at' => 'datetime',
        ];
    }

    /**
     * Default weekly working hours used at onboarding and in factories.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function defaultWorkingHours(): array
    {
        $weekday = ['open' => '09:00', 'close' => '19:00', 'break' => ['12:00', '13:30']];

        return [
            'monday' => $weekday,
            'tuesday' => $weekday,
            'wednesday' => $weekday,
            'thursday' => $weekday,
            'friday' => $weekday,
            'saturday' => ['open' => '10:00', 'close' => '14:00', 'break' => null],
            'sunday' => ['closed' => true],
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Vertical, $this>
     */
    public function vertical(): BelongsTo
    {
        return $this->belongsTo(Vertical::class);
    }

    /**
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * @return HasMany<Patient, $this>
     */
    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')->singleFile();
        $this->addMediaCollection('cover')->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        foreach (['large' => 1920, 'medium' => 800, 'thumb' => 300] as $name => $width) {
            $this->addMediaConversion($name)
                ->width($width)
                ->format('webp')
                ->quality(82)
                ->performOnCollections('logo', 'cover')
                ->queued();
        }
    }
}
