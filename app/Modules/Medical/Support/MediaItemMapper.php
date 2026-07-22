<?php

namespace App\Modules\Medical\Support;

use App\Models\Treatment;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Maps a Spatie Media item owned by a Treatment into the shared MediaItem shape
 * (resources/js/types/media.ts) consumed by the Process/Show/Case-rollup pages.
 * Every URL is the authorized streaming route — never a public/getUrl() link.
 */
final class MediaItemMapper
{
    /**
     * @return array<string, mixed>
     */
    public static function map(Treatment $treatment, Media $media): array
    {
        $isImage = str_starts_with((string) $media->mime_type, 'image/');

        return [
            'id' => $media->id,
            'name' => $media->name,
            'caption' => $media->getCustomProperty('caption'),
            'mime' => $media->mime_type,
            'size' => $media->size,
            'is_image' => $isImage,
            'thumb_url' => $isImage ? self::streamUrl($treatment, $media, 'thumb') : null,
            'preview_url' => $isImage ? self::streamUrl($treatment, $media, 'medium') : null,
            'download_url' => self::streamUrl($treatment, $media, null, true),
            'created_at' => $media->created_at->toIso8601String(),
        ];
    }

    private static function streamUrl(Treatment $treatment, Media $media, ?string $conversion, bool $download = false): string
    {
        $query = array_filter([
            'conversion' => $conversion,
            'download' => $download ? 1 : null,
        ], fn ($value) => $value !== null);

        return route('treatments.media.show', ['treatment' => $treatment->id, 'media' => $media->id] + $query);
    }
}
