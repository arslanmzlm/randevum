<?php

namespace App\Modules\Core\Contracts;

use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\HasMedia;

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
}
