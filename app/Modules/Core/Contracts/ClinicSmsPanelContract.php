<?php

namespace App\Modules\Core\Contracts;

use App\Models\Clinic;

/**
 * Extension point for the clinic settings page: the SMS preferences tab is owned by Messaging,
 * but the page lives in Core, and the shared kernel must not import a sibling module. Core
 * declares the shape here and resolves whatever the container has bound (nothing, when the
 * Messaging module is absent — the tab is then simply not rendered).
 */
interface ClinicSmsPanelContract
{
    /**
     * Null when the viewer may not see SMS settings.
     *
     * @return array{
     *     settings: array<string, bool>,
     *     templates: array<string, string|null>,
     *     defaults: array<string, string>,
     *     variables: list<string>,
     *     sample: array<string, string>,
     *     quota: array{used: int, allowance: int, remaining: int, resets_at: string},
     * }|null
     */
    public function panelData(Clinic $clinic): ?array;
}
