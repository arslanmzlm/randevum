<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ScheduleException;
use App\Modules\Core\Contracts\DoctorDirectoryContract;
use App\Modules\Core\Support\Toast;
use App\Modules\Scheduling\Http\Requests\StoreScheduleExceptionRequest;
use App\Modules\Scheduling\Http\Resources\ScheduleExceptionResource;
use App\Modules\Scheduling\Services\ScheduleExceptionService;
use App\Support\ClinicContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ScheduleExceptionController extends Controller
{
    public function __construct(
        private ScheduleExceptionService $service,
        private DoctorDirectoryContract $doctorDirectory,
        private ClinicContext $clinicContext,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ScheduleException::class);

        $user = $request->user();
        $doctors = $this->doctorDirectory->activeForClinic();
        $showPast = $request->boolean('show_past');

        $exceptions = $this->service->listForClinic(includePast: $showPast)
            ->map(fn (ScheduleException $e) => (new ScheduleExceptionResource($e))->resolve());

        return Inertia::render('availability/Index', [
            'exceptions' => $exceptions,
            'doctors' => $doctors->map(fn ($d) => ['id' => $d->id, 'display_name' => $d->display_name]),
            'ownDoctorId' => $user->doctor?->id,
            'timezone' => $this->clinicContext->timezone(),
            'showPast' => $showPast,
            // Upcoming view only: signals whether to offer the "show past" hint when the list is empty.
            'hasPast' => ! $showPast && $this->service->pastCountForClinic() > 0,
        ]);
    }

    public function store(StoreScheduleExceptionRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        // Resolve the target doctor when adding a single-doctor exception.
        $targetDoctor = null;
        if ($validated['scope'] === 'doctor') {
            $targetDoctor = $this->doctorDirectory->findForClinic((int) $validated['doctor_id']);
        }

        $this->authorize('create', [ScheduleException::class, $targetDoctor]);

        $this->service->store($validated, $request->user());

        if ($validated['scope'] === 'clinic') {
            Toast::success(__('messages.schedule_exception.clinic_wide_added'));
        } else {
            Toast::success(__('messages.schedule_exception.added'));
        }

        return to_route('schedule-exceptions.index');
    }

    public function destroy(Request $request, ScheduleException $scheduleException): RedirectResponse
    {
        $this->authorize('delete', $scheduleException);

        $this->service->delete($scheduleException);

        Toast::success(__('messages.schedule_exception.removed'));

        return to_route('schedule-exceptions.index');
    }
}
