<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AppointmentType;
use App\Modules\Core\Support\Toast;
use App\Modules\Scheduling\Http\Requests\StoreAppointmentTypeRequest;
use App\Modules\Scheduling\Http\Requests\UpdateAppointmentTypeRequest;
use App\Modules\Scheduling\Http\Resources\AppointmentTypeResource;
use App\Modules\Scheduling\Services\AppointmentTypeService;
use App\Support\FilterHelper;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AppointmentTypeController extends Controller
{
    public function __construct(
        private AppointmentTypeService $service,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', AppointmentType::class);

        $paginator = $this->service->paginateForActiveClinic();

        return Inertia::render('appointment-types/Index', [
            'appointmentTypes' => AppointmentTypeResource::collection($paginator),
            'query' => FilterHelper::requestState([
                'is_active' => 'boolean',
            ]),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', AppointmentType::class);

        return Inertia::render('appointment-types/Create');
    }

    public function store(StoreAppointmentTypeRequest $request): RedirectResponse
    {
        $this->authorize('create', AppointmentType::class);

        $this->service->create($request->validated());

        Toast::success(__('messages.appointment_type.created'));

        return to_route('appointment-types.index');
    }

    public function edit(AppointmentType $appointmentType): Response
    {
        $this->authorize('update', $appointmentType);

        return Inertia::render('appointment-types/Edit', [
            'appointmentType' => (new AppointmentTypeResource($appointmentType))->resolve(),
        ]);
    }

    public function update(UpdateAppointmentTypeRequest $request, AppointmentType $appointmentType): RedirectResponse
    {
        $this->authorize('update', $appointmentType);

        $this->service->update($appointmentType, $request->validated());

        Toast::success(__('messages.appointment_type.updated'));

        return to_route('appointment-types.index');
    }

    public function destroy(AppointmentType $appointmentType): RedirectResponse
    {
        $this->authorize('delete', $appointmentType);

        $this->service->delete($appointmentType);

        Toast::success(__('messages.appointment_type.deleted'));

        return to_route('appointment-types.index');
    }
}
