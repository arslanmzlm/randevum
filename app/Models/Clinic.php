<?php

namespace App\Models;

use App\Models\Concerns\HasImageUrls;
use Database\Factories\ClinicFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Propaganistas\LaravelPhone\Casts\E164PhoneNumberCast;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Clinic extends Model implements HasMedia
{
    /** @use HasFactory<ClinicFactory> */
    use HasFactory, HasImageUrls, InteractsWithMedia, SoftDeletes;

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

    /**
     * @return HasMany<ClinicSmsSetting, $this>
     */
    public function smsSettings(): HasMany
    {
        return $this->hasMany(ClinicSmsSetting::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')->singleFile();
        $this->addMediaCollection('cover')->singleFile();
        $this->addMediaCollection('cover_mobile')->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        // Logo: free-form, Fit::Max (never upscales). Bounding box per conversion.
        foreach ([
            'large' => [1024, 1024],
            'medium' => [512, 512],
            'thumb' => [128, 128],
        ] as $name => [$w, $h]) {
            $this->addMediaConversion($name)
                ->fit(Fit::Max, $w, $h)
                ->format('webp')
                ->quality(90)
                ->performOnCollections('logo')
                ->queued();
        }

        // Cover 16:9: exact crop — upload validation enforces min 1920×1080 so no upscaling.
        foreach ([
            'large' => [1920, 1080],
            'medium' => [1280, 720],
            'thumb' => [640, 360],
        ] as $name => [$w, $h]) {
            $this->addMediaConversion($name)
                ->fit(Fit::Crop, $w, $h)
                ->format('webp')
                ->quality(90)
                ->performOnCollections('cover')
                ->queued();
        }

        // Cover mobile 1:1: exact crop — upload validation enforces min 1440×1440.
        foreach ([
            'large' => [1440, 1440],
            'medium' => [720, 720],
            'thumb' => [320, 320],
        ] as $name => [$w, $h]) {
            $this->addMediaConversion($name)
                ->fit(Fit::Crop, $w, $h)
                ->format('webp')
                ->quality(90)
                ->performOnCollections('cover_mobile')
                ->queued();
        }
    }
}
