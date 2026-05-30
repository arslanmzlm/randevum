<?php

namespace App\Modules\Media\Support;

use Illuminate\Support\Str;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\Support\FileNamer\FileNamer;

/** Slugs stored file names so Türkçe/space chars never reach the disk/S3 key (the `name` column keeps the original). */
class SluggedFileNamer extends FileNamer
{
    public function originalFileName(string $fileName): string
    {
        return Str::slug(pathinfo($fileName, PATHINFO_FILENAME)) ?: 'file';
    }

    public function conversionFileName(string $fileName, Conversion $conversion): string
    {
        return $this->originalFileName($fileName).'-'.$conversion->getName();
    }

    public function responsiveFileName(string $fileName): string
    {
        return $this->originalFileName($fileName);
    }
}
