<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use App\Models\Concerns\HasImageUrls;
use Database\Factories\DoctorFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Doctor extends Model implements HasMedia
{
    /** @use HasFactory<DoctorFactory> */
    use BelongsToClinic, HasFactory, HasImageUrls, InteractsWithMedia, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'clinic_id',
        'title',
        'specialization',
        'bio',
        'license_number',
        'certificate',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'clinic_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Title-prefixed full name; callers must eager-load `user`.
     *
     * @return Attribute<string, never>
     */
    protected function displayName(): Attribute
    {
        return Attribute::get(fn (): string => trim("{$this->title} {$this->user?->name}"));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Clinic, $this>
     */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatar')->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        foreach ([
            'medium' => 512,
            'thumb' => 128,
        ] as $name => $size) {
            $this->addMediaConversion($name)
                ->fit(Fit::Crop, $size, $size)
                ->format('webp')
                ->quality(90)
                ->performOnCollections('avatar')
                ->queued();
        }
    }
}
