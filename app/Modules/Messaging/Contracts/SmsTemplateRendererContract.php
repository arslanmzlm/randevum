<?php

namespace App\Modules\Messaging\Contracts;

use App\Enums\SmsType;
use App\Models\Clinic;

/**
 * The single seam for building an SMS body: resolves the clinic's custom
 * template (or the lang default) and substitutes the allowlisted variables.
 * Cross-module callers (Scheduling) depend on this contract only, never the
 * concrete renderer.
 */
interface SmsTemplateRendererContract
{
    /**
     * @param  array<string, string>  $vars  allowlisted variable values, keyed without the leading ':'
     */
    public function resolve(Clinic $clinic, SmsType $type, array $vars): string;
}
