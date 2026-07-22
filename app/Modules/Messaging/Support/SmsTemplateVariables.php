<?php

namespace App\Modules\Messaging\Support;

/**
 * The single allowlist of `:token` variables a clinic may use in a custom SMS
 * template — shared by save-time validation (FormRequest) and the edit-page
 * props (Controller). `:service` is deliberately excluded (nullable → fuzzy).
 */
class SmsTemplateVariables
{
    /**
     * @var list<string>
     */
    public const ALLOWED = ['clinic', 'date', 'time', 'patient', 'doctor'];
}
