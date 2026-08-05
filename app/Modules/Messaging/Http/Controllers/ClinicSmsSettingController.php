<?php

namespace App\Modules\Messaging\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ClinicSmsSetting;
use App\Modules\Core\Support\Toast;
use App\Modules\Messaging\Http\Requests\UpdateClinicSmsSettingsRequest;
use App\Modules\Messaging\Services\ClinicSmsSettingService;
use App\Support\ClinicContext;
use Illuminate\Http\RedirectResponse;

class ClinicSmsSettingController extends Controller
{
    public function __construct(
        private ClinicContext $clinicContext,
        private ClinicSmsSettingService $service,
    ) {}

    /**
     * SMS preferences moved into the clinic profile as a tab; the old standalone URL still
     * resolves so bookmarks and older links land on that tab instead of a 404.
     */
    public function edit(): RedirectResponse
    {
        $this->authorize('view', ClinicSmsSetting::class);

        return redirect()->route('clinic.edit', ['tab' => 'sms']);
    }

    public function update(UpdateClinicSmsSettingsRequest $request): RedirectResponse
    {
        $this->authorize('update', ClinicSmsSetting::class);

        $clinicId = $this->clinicContext->id();
        abort_unless($clinicId !== null, 404);

        $this->service->update($clinicId, $request->settings(), $request->templates());

        Toast::success(__('messages.sms_settings.updated'));

        return redirect()->back();
    }
}
