<?php

namespace App\Modules\Media\Services;

use App\Modules\Core\Contracts\MediaServiceContract;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\HasMedia;

/**
 * Media-module wrapper around Spatie Medialibrary. All controllers that need to
 * store or remove media MUST call this service via MediaServiceContract —
 * never call addMedia/clearMedia directly.
 */
class MediaService implements MediaServiceContract
{
    /**
     * Replace the single file in a collection with the uploaded file.
     *
     * Because all collections are declared with ->singleFile(), adding a new
     * file automatically removes the previous one via Medialibrary's built-in
     * single-file behaviour. Conversions stay queued (media queue).
     */
    public function setImage(HasMedia $model, string $collection, UploadedFile $file): void
    {
        $model->addMedia($file)->toMediaCollection($collection);
    }

    /**
     * Clear all files from a collection (removes DB row + stored file).
     */
    public function removeImage(HasMedia $model, string $collection): void
    {
        $model->clearMediaCollection($collection);
    }
}
