<?php

namespace App\Modules\Medical\Services;

use App\Models\Treatment;
use App\Modules\Media\Contracts\MediaServiceContract;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Treatment media upload/delete. Kept as its own service (not folded into
 * TreatmentService) since it's a distinct concern with its own delete-window rule.
 */
class TreatmentMediaService
{
    private const COLLECTION = 'treatment_media';

    public function __construct(
        private MediaServiceContract $mediaService,
    ) {}

    public function upload(Treatment $treatment, UploadedFile $file, ?string $caption): Media
    {
        return $this->mediaService->addFile($treatment, self::COLLECTION, $file, [
            'caption' => $caption,
        ]);
    }

    /**
     * Hard-deletes within the 48h correction window (platform.media.delete_window);
     * blocks afterwards. Full KVKK soft-delete + retention lifecycle is a later feature.
     *
     * @throws ValidationException
     */
    public function delete(Treatment $treatment, Media $media): void
    {
        $windowSeconds = (int) config('platform.media.delete_window');

        if ($media->created_at->copy()->addSeconds($windowSeconds)->isPast()) {
            throw ValidationException::withMessages([
                'media' => [__('treatment.errors.media_delete_window_expired')],
            ]);
        }

        $this->mediaService->deleteMedia($treatment, $media);
    }
}
