<?php

namespace App\Modules\Messaging\Http\Controllers;

use App\Enums\SmsType;
use App\Http\Controllers\Controller;
use App\Models\ClinicSmsSetting;
use App\Modules\Core\Support\Toast;
use App\Modules\Messaging\Http\Requests\UpdateClinicSmsSettingsRequest;
use App\Modules\Messaging\Services\ClinicSmsSettingService;
use App\Modules\Messaging\Services\SmsQuotaService;
use App\Support\ClinicContext;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ClinicSmsSettingController extends Controller
{
    public function __construct(
        private ClinicContext $clinicContext,
        private ClinicSmsSettingService $service,
        private SmsQuotaService $quotaService,
    ) {}

    public function edit(): Response
    {
        $this->authorize('view', ClinicSmsSetting::class);

        $clinicId = $this->clinicContext->id();
        abort_unless($clinicId !== null, 404);

        return Inertia::render('clinic/SmsSettings', [
            'settings' => $this->service->current($clinicId),
            'types' => array_map(
                fn (SmsType $t) => ['value' => $t->value],
                SmsType::clinicScopedCases(),
            ),
            'quota' => $this->quotaService->usage($clinicId),
        ]);
    }

    public function update(UpdateClinicSmsSettingsRequest $request): RedirectResponse
    {
        $this->authorize('update', ClinicSmsSetting::class);

        $clinicId = $this->clinicContext->id();
        abort_unless($clinicId !== null, 404);

        $this->service->update($clinicId, $request->settings());

        Toast::success(__('messages.sms_settings.updated'));

        return redirect()->back();
    }
}
