<?php

namespace App\Modules\Media\Services;

use App\Modules\Media\Contracts\MediaServiceContract;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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

    /**
     * Add one file to a multi-file collection (does not replace existing files).
     *
     * @param  array<string, mixed>  $customProperties
     */
    public function addFile(HasMedia $model, string $collection, UploadedFile $file, array $customProperties = []): Media
    {
        return $model->addMedia($file)
            ->withCustomProperties($customProperties)
            ->toMediaCollection($collection);
    }

    /**
     * Hard-delete one media item. Re-asserts ownership (model_type/model_id) as
     * defense in depth — callers (controllers) have already scoped {media} to
     * {treatment} and its collection before reaching here.
     *
     * Compares against getMorphClass(), NOT the FQCN: some HasMedia owners (e.g.
     * Treatment) are also registered in the app's Relation::morphMap(), so Spatie
     * persists the mapped alias ("treatment") to model_type, not the class name.
     */
    public function deleteMedia(HasMedia $model, Media $media): void
    {
        abort_unless(
            $media->model_type === $model->getMorphClass() && (int) $media->model_id === $model->getKey(),
            404,
        );

        $media->delete();
    }

    public function updateCaption(Media $media, ?string $caption): void
    {
        $media->setCustomProperty('caption', $caption);
        $media->save();
    }
}
