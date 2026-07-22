<?php

namespace App\Modules\Core\Contracts;

use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Cross-module contract for media storage operations.
 *
 * Lives in Core (the shared kernel) so any module can depend on it
 * without creating a cross-sibling import. The Media module binds the
 * concrete implementation in MediaServiceProvider.
 */
interface MediaServiceContract
{
    /**
     * Replace the single file in a collection with the uploaded file.
     * Conversions are queued on the media queue.
     */
    public function setImage(HasMedia $model, string $collection, UploadedFile $file): void;

    /**
     * Clear all files from a collection (removes DB row + stored file).
     */
    public function removeImage(HasMedia $model, string $collection): void;

    /**
     * Add one file to a multi-file collection (does not replace existing files).
     *
     * @param  array<string, mixed>  $customProperties
     */
    public function addFile(HasMedia $model, string $collection, UploadedFile $file, array $customProperties = []): Media;

    /**
     * Hard-delete one media item (DB row + stored file + conversions).
     *
     * Callers must have already verified the media belongs to the given model and
     * collection — this only re-asserts model_type/model_id as defense in depth.
     */
    public function deleteMedia(HasMedia $model, Media $media): void;

    /**
     * Set/replace a media item's free-text caption (stored in custom_properties).
     */
    public function updateCaption(Media $media, ?string $caption): void;
}
