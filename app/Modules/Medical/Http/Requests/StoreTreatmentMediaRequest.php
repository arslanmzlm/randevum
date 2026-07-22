<?php

namespace App\Modules\Medical\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class StoreTreatmentMediaRequest extends FormRequest
{
    /**
     * Extension allowlist, checked against the CLIENT-declared filename — no SVG/audio/video.
     */
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'pdf', 'docx', 'xlsx'];

    /**
     * Real-MIME allowlist, checked as defense-in-depth for every extension EXCEPT
     * heic/heif (see formatRule()).
     */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    private const HEIC_EXTENSIONS = ['heic', 'heif'];

    /**
     * Authorization is handled by the controller via $this->authorize('uploadMedia', $treatment).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `max` is config-driven (platform.media.max_file_size) so a change stays one env var.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:'.intdiv((int) config('platform.media.max_file_size'), 1024),
                $this->formatRule(),
            ],
            'caption' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Custom rule instead of stacking Laravel's built-in `mimes`/`mimetypes` rules:
     * both of those derive the extension from the CONTENT-sniffed MIME type
     * (UploadedFile::guessExtension()), so a genuine HEIC/HEIF file whose container
     * server-side fileinfo can't decode (commonly reported as
     * application/octet-stream) would fail `mimes` too, not just `mimetypes` — the
     * plan's "trust the extension for HEIC" intent is unreachable with the stock
     * rules. This rule checks the CLIENT-declared filename extension directly, and
     * exempts only heic/heif from the real-MIME check; every other extension still
     * requires both an allowed extension AND a recognized real MIME type.
     */
    private function formatRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! $value instanceof UploadedFile) {
                return;
            }

            $extension = strtolower($value->getClientOriginalExtension());

            if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                $fail(__('validation.mimes', [
                    'attribute' => __('validation.attributes.file'),
                    'values' => implode(', ', self::ALLOWED_EXTENSIONS),
                ]));

                return;
            }

            if (in_array($extension, self::HEIC_EXTENSIONS, true)) {
                return;
            }

            if (! in_array($value->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
                $fail(__('validation.mimetypes', [
                    'attribute' => __('validation.attributes.file'),
                    'values' => implode(', ', self::ALLOWED_MIME_TYPES),
                ]));
            }
        };
    }
}
