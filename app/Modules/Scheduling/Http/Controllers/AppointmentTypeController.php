<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AppointmentType;
use App\Modules\Core\Support\CrudResponse;
use App\Modules\Scheduling\Http\Requests\StoreAppointmentTypeRequest;
use App\Modules\Scheduling\Http\Requests\UpdateAppointmentTypeRequest;
use App\Modules\Scheduling\Http\Resources\AppointmentTypeResource;
use App\Modules\Scheduling\Services\AppointmentTypeService;
use App\Support\FilterHelper;
use Illuminate\Http\JsonResponse;
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

        $editing = $this->service->findForActiveClinic($request->integer('edit'));

        return Inertia::render('appointment-types/Index', [
            'appointmentTypes' => AppointmentTypeResource::collection($paginator),
            'query' => FilterHelper::requestState([
                'is_active' => 'boolean',
            ]),
            // ?edit=<id> deep link: resolved here so the dialog opens for a row on any page.
            'editing' => CrudResponse::editingProp($request, $editing, AppointmentTypeResource::class),
        ]);
    }

    public function create(): RedirectResponse
    {
        $this->authorize('create', AppointmentType::class);

        return to_route('appointment-types.index', ['new' => 1]);
    }

    public function store(StoreAppointmentTypeRequest $request): RedirectResponse|JsonResponse
    {
        $this->authorize('create', AppointmentType::class);

        $type = $this->service->create($request->validated());

        return CrudResponse::saved(
            $request,
            new AppointmentTypeResource($type),
            __('messages.appointment_type.created'),
            'appointment-types.index',
        );
    }

    public function edit(AppointmentType $appointmentType): RedirectResponse
    {
        $this->authorize('update', $appointmentType);

        return to_route('appointment-types.index', ['edit' => $appointmentType->id]);
    }

    public function update(UpdateAppointmentTypeRequest $request, AppointmentType $appointmentType): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $appointmentType);

        $type = $this->service->update($appointmentType, $request->validated());

        return CrudResponse::saved(
            $request,
            new AppointmentTypeResource($type),
            __('messages.appointment_type.updated'),
            'appointment-types.index',
        );
    }

    public function destroy(AppointmentType $appointmentType): RedirectResponse
    {
        $this->authorize('delete', $appointmentType);

        $this->service->delete($appointmentType);

        return CrudResponse::deleted(__('messages.appointment_type.deleted'), 'appointment-types.index');
    }
}
