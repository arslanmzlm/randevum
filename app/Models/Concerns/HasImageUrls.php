<?php

namespace App\Models\Concerns;

use Spatie\MediaLibrary\HasMedia;

/** @mixin HasMedia */
trait HasImageUrls
{
    /** Falls back to the original until the queued conversion is generated; null if the collection is empty. */
    public function imageUrl(string $collection, string $conversion = 'medium'): ?string
    {
        $media = $this->getFirstMedia($collection);

        if ($media === null) {
            return null;
        }

        return $media->hasGeneratedConversion($conversion)
            ? $media->getUrl($conversion)
            : $media->getUrl();
    }
}
