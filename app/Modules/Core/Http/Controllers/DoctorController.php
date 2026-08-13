<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Modules\Core\Exceptions\DeletionBlockedException;
use App\Modules\Core\Http\Requests\OffboardDoctorRequest;
use App\Modules\Core\Http\Requests\StoreDoctorRequest;
use App\Modules\Core\Http\Requests\UpdateDoctorAvatarRequest;
use App\Modules\Core\Http\Requests\UpdateDoctorProfileRequest;
use App\Modules\Core\Http\Resources\DoctorResource;
use App\Modules\Core\Services\DoctorProfileService;
use App\Modules\Core\Support\Toast;
use App\Modules\Media\Contracts\MediaServiceContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DoctorController extends Controller
{
    public function __construct(
        private DoctorProfileService $profileService,
        private MediaServiceContract $mediaService,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Doctor::class);

        $user = $request->user();
        $hasOwnProfile = $user->doctor()->withoutGlobalScopes()->exists();

        return Inertia::render('doctors/Index', [
            'doctors' => $this->profileService->listForClinic()->map(fn (Doctor $doctor) => (new DoctorResource($doctor))->resolve()),
            'hasOwnProfile' => $hasOwnProfile,
            'canCreateOwn' => $user->can('doctors.createOwn') && ! $hasOwnProfile,
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

        return to_route('doctors.edit', $doctor);
    }

    public function storeOwn(Request $request): RedirectResponse
    {
        $this->authorize('createOwn', Doctor::class);

        $doctor = $this->profileService->createOwnProfile($request->user());

        Toast::success(__('messages.doctor.profile_created'));

        return to_route('doctors.edit', $doctor);
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

            return to_route('doctors.index');
        }

        $this->authorize('update', $doctor);

        return Inertia::render('doctors/Edit', $this->editProps($request, $doctor));
    }

    public function update(UpdateDoctorProfileRequest $request, Doctor $doctor): RedirectResponse
    {
        $this->authorize('update', $doctor);

        $canManage = $request->user()->can('doctors.update');
        $doctor->loadMissing('user');

        $this->profileService->update($doctor, $request->validated(), $canManage);

        Toast::success(__('messages.doctor.profile_updated'));

        return back();
    }

    public function destroy(Request $request, Doctor $doctor): RedirectResponse
    {
        $this->authorize('delete', $doctor);

        try {
            $this->profileService->remove($doctor, $request->user());
        } catch (DeletionBlockedException $e) {
            Toast::warning($e->getMessage());

            return back();
        }

        Toast::success(__('messages.doctor.doctor_removed'));

        return to_route('doctors.index');
    }

    public function updateAvatar(UpdateDoctorAvatarRequest $request, Doctor $doctor): RedirectResponse
    {
        $this->authorize('update', $doctor);

        $this->mediaService->setImage($doctor, 'avatar', $request->file('image'));

        Toast::success(__('messages.doctor.avatar_updated'));

        return back();
    }

    public function removeAvatar(Request $request, Doctor $doctor): RedirectResponse
    {
        $this->authorize('update', $doctor);

        $this->mediaService->removeImage($doctor, 'avatar');

        Toast::success(__('messages.doctor.avatar_removed'));

        return back();
    }

    public function offboardPreview(Request $request, Doctor $doctor): JsonResponse
    {
        $this->authorize('offboard', $doctor);

        $doctor->loadMissing('user');

        return response()->json([
            'upcoming_appointments_count' => $this->profileService->previewOffboard($doctor),
            'is_offboarded' => $doctor->left_at !== null,
            'display_name' => $doctor->display_name,
        ]);
    }

    public function offboard(OffboardDoctorRequest $request, Doctor $doctor): RedirectResponse
    {
        $this->authorize('offboard', $doctor);

        $data = $request->validated();

        $doctor->loadMissing('user');

        $count = $this->profileService->offboard(
            $doctor,
            (bool) ($data['cancel_appointments'] ?? false),
            $data['reason'] ?? null,
            $request->user(),
        );

        Toast::success(__('messages.doctor.offboarded', [
            'name' => $doctor->display_name,
            'count' => $count,
        ]));

        return to_route('doctors.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function editProps(Request $request, Doctor $doctor): array
    {
        $user = $request->user();

        return [
            'doctor' => (new DoctorResource($doctor))->resolve(),
            // Ownership stays a page prop — it cannot be expressed as a permission. The
            // doctors.update gate is read on the client via useCan().
            'canEditSelf' => $doctor->user_id === $user->id,
        ];
    }
}
