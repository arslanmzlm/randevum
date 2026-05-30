<?php

use App\Modules\Media\Support\SluggedFileNamer;

it('slugs the original file name to ASCII, dropping the extension', function (string $input, string $expected): void {
    expect((new SluggedFileNamer)->originalFileName($input))->toBe($expected);
})->with([
    'turkish + spaces' => ['BAŞKA DOSYA DENİYORĞUM ŞİMDİ.png', 'baska-dosya-deniyorgum-simdi'],
    'turkish dotted I' => ['İŞ.png', 'is'],
    'already clean' => ['logo.PNG', 'logo'],
    'non-sluggable falls back' => ['日本語.png', 'file'],
]);
