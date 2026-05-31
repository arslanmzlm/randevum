<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Modules\Core\Contracts\MediaServiceContract;
use App\Modules\Core\Http\Requests\StoreDoctorRequest;
use App\Modules\Core\Http\Requests\UpdateDoctorAvatarRequest;
use App\Modules\Core\Http\Requests\UpdateDoctorProfileRequest;
use App\Modules\Core\Http\Resources\DoctorResource;
use App\Modules\Core\Repositories\DoctorRepository;
use App\Modules\Core\Services\DoctorProfileService;
use App\Modules\Core\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DoctorController extends Controller
{
    public function __construct(
        private DoctorRepository $repository,
        private DoctorProfileService $profileService,
        private MediaServiceContract $mediaService,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Doctor::class);

        $user = $request->user();
        $canManage = $user->hasRole('owner') || $user->hasRole('manager');
        $hasOwnProfile = $user->doctor()->withoutGlobalScopes()->exists();

        return Inertia::render('doctors/Index', [
            'doctors' => $this->repository->forClinicList()->map(fn (Doctor $doctor) => (new DoctorResource($doctor))->resolve()),
            'canManage' => $canManage,
            'hasOwnProfile' => $hasOwnProfile,
            'canCreateOwn' => $user->hasRole('owner') && ! $hasOwnProfile,
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Doctor::class);

        return Inertia::render('doctors/Create');
    }

    public function store(StoreDoctorRequest $request): RedirectResponse
    {
        $this->authorize('create', Doctor::class);

        $doctor = $this->profileService->addDoctor($request->validated());

        Toast::success(__('messages.doctor.doctor_added'));

        return redirect()->route('doctors.edit', $doctor);
    }

    public function storeOwn(Request $request): RedirectResponse
    {
        $this->authorize('createOwn', Doctor::class);

        $doctor = $this->profileService->createOwnProfile($request->user());

        Toast::success(__('messages.doctor.profile_created'));

        return redirect()->route('doctors.edit', $doctor);
    }

    public function edit(Request $request, Doctor $doctor): Response
    {
        $this->authorize('update', $doctor);

        $doctor->loadMissing(['user', 'media']);

        return Inertia::render('doctors/Edit', $this->editProps($request, $doctor));
    }

    public function mine(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $doctor = Doctor::with(['user', 'media'])->where('user_id', $user->id)->first();

        if ($doctor === null) {
            Toast::info(__('messages.doctor.no_profile_yet'));

            return redirect()->route('doctors.index');
        }

        $this->authorize('update', $doctor);

        return Inertia::render('doctors/Edit', $this->editProps($request, $doctor));
    }

    public function update(UpdateDoctorProfileRequest $request, Doctor $doctor): RedirectResponse
    {
        $this->authorize('update', $doctor);

        $canManage = $request->user()->hasRole('owner') || $request->user()->hasRole('manager');
        $doctor->loadMissing('user');

        $this->profileService->update($doctor, $request->validated(), $canManage);

        Toast::success(__('messages.doctor.profile_updated'));

        return redirect()->back();
    }

    public function destroy(Request $request, Doctor $doctor): RedirectResponse
    {
        $this->authorize('delete', $doctor);

        $this->profileService->remove($doctor);

        Toast::success(__('messages.doctor.doctor_removed'));

        return redirect()->route('doctors.index');
    }

    public function updateAvatar(UpdateDoctorAvatarRequest $request, Doctor $doctor): RedirectResponse
    {
        $this->authorize('update', $doctor);

        $this->mediaService->setImage($doctor, 'avatar', $request->file('image'));

        Toast::success(__('messages.doctor.avatar_updated'));

        return redirect()->back();
    }

    public function removeAvatar(Request $request, Doctor $doctor): RedirectResponse
    {
        $this->authorize('update', $doctor);

        $this->mediaService->removeImage($doctor, 'avatar');

        Toast::success(__('messages.doctor.avatar_removed'));

        return redirect()->back();
    }

    /**
     * @return array<string, mixed>
     */
    private function editProps(Request $request, Doctor $doctor): array
    {
        $user = $request->user();

        return [
            'doctor' => (new DoctorResource($doctor))->resolve(),
            'canManage' => $user->hasRole('owner') || $user->hasRole('manager'),
            'canEditSelf' => $doctor->user_id === $user->id,
        ];
    }
}
