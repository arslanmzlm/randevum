<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Modules\Core\Support\Toast;
use App\Modules\Identity\Http\Requests\SwitchClinicRequest;
use App\Modules\Identity\Services\ClinicSwitchService;
use Illuminate\Http\RedirectResponse;

class ClinicSwitchController extends Controller
{
    public function __construct(private ClinicSwitchService $switchService) {}

    public function store(SwitchClinicRequest $request): RedirectResponse
    {
        // withoutGlobalScopes(): the target branch is by definition outside the
        // currently active ClinicScope.
        $clinic = Clinic::withoutGlobalScopes()->findOrFail($request->validated('clinic_id'));

        $this->authorize('switchTo', $clinic);

        $this->switchService->switchTo($request->user(), $clinic);

        Toast::success(__('branch.switched', ['name' => $clinic->name]));

        // A full redirect, not back(): the current page may reference a record that
        // does not exist (or is empty) in the new branch.
        return redirect()->route('dashboard');
    }
}
