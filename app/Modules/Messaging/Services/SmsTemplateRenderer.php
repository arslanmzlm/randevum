<?php

namespace App\Modules\Messaging\Services;

use App\Enums\SmsType;
use App\Models\Clinic;
use App\Modules\Messaging\Contracts\SmsTemplateRendererContract;
use App\Modules\Messaging\Repositories\ClinicSmsSettingRepository;

class SmsTemplateRenderer implements SmsTemplateRendererContract
{
    public function __construct(
        private ClinicSmsSettingRepository $repository,
    ) {}

    public function resolve(Clinic $clinic, SmsType $type, array $vars): string
    {
        // Normalize 'tr_TR' → 'tr' so lang/ dirs resolve correctly (matches the
        // triggering services' own Carbon locale normalization).
        $lang = strtolower(explode('_', $clinic->locale)[0]);

        $custom = $this->repository->templateFor($clinic->id, $type);

        $body = $custom !== null && $custom !== ''
            ? $custom
            : __($type->defaultBodyKey(), [], $lang);

        // Save-time validation already guarantees only allowlisted tokens exist,
        // so no render-time rejection of unknown ':x' tokens is needed here.
        return strtr($body, $this->tokens($vars));
    }

    /**
     * @param  array<string, string>  $vars
     * @return array<string, string>
     */
    private function tokens(array $vars): array
    {
        $tokens = [];

        foreach ($vars as $key => $value) {
            $tokens[":{$key}"] = $value;
        }

        return $tokens;
    }
}
