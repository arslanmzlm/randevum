<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Clinic;
use App\Models\Country;
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
    public function __construct(
        private ClinicContext $clinicContext,
        private ClinicProfileService $profileService,
        private MediaServiceContract $mediaService,
    ) {}

    public function edit(): Response
    {
        $clinic = Clinic::with('vertical')->findOrFail($this->clinicContext->id());

        $this->authorize('update', $clinic);

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
        ]);
    }

    public function update(UpdateClinicRequest $request): RedirectResponse
    {
        $clinic = Clinic::findOrFail($this->clinicContext->id());

        $this->authorize('update', $clinic);

        $this->profileService->update($clinic, $request->validated());

        Toast::success(__('clinic.profile_updated'));

        return redirect()->back();
    }

    public function updateMedia(UpdateClinicMediaRequest $request, string $collection): RedirectResponse
    {
        abort_unless(in_array($collection, ['logo', 'cover', 'cover_mobile'], true), 404);

        $clinic = Clinic::findOrFail($this->clinicContext->id());

        $this->authorize('update', $clinic);

        $this->mediaService->setImage($clinic, $collection, $request->file('image'));

        Toast::success(__('clinic.media_updated'));

        return redirect()->back();
    }

    public function removeMedia(Request $request, string $collection): RedirectResponse
    {
        abort_unless(in_array($collection, ['logo', 'cover', 'cover_mobile'], true), 404);

        $clinic = Clinic::findOrFail($this->clinicContext->id());

        $this->authorize('update', $clinic);

        $this->mediaService->removeImage($clinic, $collection);

        Toast::success(__('clinic.media_removed'));

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
            'default_slot_duration_minutes' => $clinic->default_slot_duration_minutes,
            'working_hours' => $clinic->working_hours,
            'logo_url' => $clinic->imageUrl('logo'),
            'cover_url' => $clinic->imageUrl('cover'),
            'cover_mobile_url' => $clinic->imageUrl('cover_mobile'),
        ];
    }
}
