<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Clinic;
use App\Models\Country;
use App\Modules\Core\Contracts\ClinicSmsPanelContract;
use App\Modules\Core\Contracts\MediaServiceContract;
use App\Modules\Core\Http\Requests\UpdateClinicMediaRequest;
use App\Modules\Core\Http\Requests\UpdateClinicRequest;
use App\Modules\Core\Services\ClinicProfileService;
use App\Modules\Core\Support\Toast;
use App\Support\ClinicContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClinicController extends Controller
{
    /** @var list<string> */
    private const MEDIA_COLLECTIONS = ['logo', 'logo_dark', 'logo_icon', 'cover', 'cover_mobile'];

    public function __construct(
        private ClinicContext $clinicContext,
        private ClinicProfileService $profileService,
        private MediaServiceContract $mediaService,
    ) {}

    /**
     * Clinic settings. Each tab carries its own gate: the profile fields need clinic.update
     * (owner), the SMS tab needs smsSettings.view (owner/manager/receptionist). A viewer holding
     * either one gets the page with only their tabs — SMS preferences used to be a separate page,
     * and merging it in must not lock out the roles that manage it.
     */
    public function edit(Request $request): Response
    {
        $clinic = Clinic::with('vertical')->findOrFail($this->clinicContext->id());

        $canEditClinic = $request->user()->can('update', $clinic);
        // Bound by the Messaging module; absent means no SMS tab, not an error.
        $sms = app()->bound(ClinicSmsPanelContract::class)
            ? app(ClinicSmsPanelContract::class)->panelData($clinic)
            : null;

        abort_unless($canEditClinic || $sms !== null, 403);

        return Inertia::render('clinic/Edit', [
            'clinic' => $this->clinicData($clinic),
            'vertical' => [
                'id' => $clinic->vertical->id,
                'name' => $clinic->vertical->slug,
            ],
            'countries' => Country::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'code']),
            'cities' => City::where('country_id', $clinic->country_id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            // SMS preferences are a tab on this page rather than a separate screen. Null when the
            // viewer lacks smsSettings.view — the tab is then not rendered at all.
            'sms' => $sms,
            'mapDefaults' => [
                'lat' => (float) config('platform.map.default_center.lat'),
                'lng' => (float) config('platform.map.default_center.lng'),
                'zoom' => (int) config('platform.map.default_zoom'),
                'selected_zoom' => (int) config('platform.map.selected_zoom'),
            ],
        ]);
    }

    public function update(UpdateClinicRequest $request): RedirectResponse
    {
        $clinic = $this->clinicContext->clinicOrFail();

        $this->authorize('update', $clinic);

        $this->profileService->update($clinic, $request->validated());

        Toast::success(__('messages.clinic.profile_updated'));

        return redirect()->back();
    }

    public function updateMedia(UpdateClinicMediaRequest $request, string $collection): RedirectResponse
    {
        abort_unless(in_array($collection, self::MEDIA_COLLECTIONS, true), 404);

        $clinic = $this->clinicContext->clinicOrFail();

        $this->authorize('update', $clinic);

        $this->mediaService->setImage($clinic, $collection, $request->file('image'));

        Toast::success(__('messages.clinic.media_updated'));

        return redirect()->back();
    }

    public function removeMedia(Request $request, string $collection): RedirectResponse
    {
        abort_unless(in_array($collection, self::MEDIA_COLLECTIONS, true), 404);

        $clinic = $this->clinicContext->clinicOrFail();

        $this->authorize('update', $clinic);

        $this->mediaService->removeImage($clinic, $collection);

        Toast::success(__('messages.clinic.media_removed'));

        return redirect()->back();
    }

    /**
     * @return array<string, mixed>
     */
    private function clinicData(Clinic $clinic): array
    {
        return [
            'id' => $clinic->id,
            'name' => $clinic->name,
            'slug' => $clinic->slug,
            'description' => $clinic->description,
            'phone' => $clinic->phone,
            'email' => $clinic->email,
            'website' => $clinic->website,
            'country_id' => $clinic->country_id,
            'city_id' => $clinic->city_id,
            'district' => $clinic->district,
            'address' => $clinic->address,
            'postal_code' => $clinic->postal_code,
            // decimal:7 cast returns a string; the props contract types these `number | null`.
            'latitude' => $clinic->latitude === null ? null : (float) $clinic->latitude,
            'longitude' => $clinic->longitude === null ? null : (float) $clinic->longitude,
            'default_slot_duration_minutes' => $clinic->default_slot_duration_minutes,
            'auto_no_show_enabled' => $clinic->auto_no_show_enabled,
            'auto_no_show_grace_hours' => $clinic->auto_no_show_grace_hours,
            'working_hours' => $clinic->working_hours,
            'logo_url' => $clinic->imageUrl('logo'),
            // Raw collection, no fallback: the uploader must show what is actually stored here.
            'logo_dark_url' => $clinic->imageUrl('logo_dark'),
            'logo_icon_url' => $clinic->imageUrl('logo_icon'),
            'cover_url' => $clinic->imageUrl('cover'),
            'cover_mobile_url' => $clinic->imageUrl('cover_mobile'),
        ];
    }
}
