<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Modules\Catalog\Http\Requests\StoreServiceRequest;
use App\Modules\Catalog\Http\Requests\UpdateServiceRequest;
use App\Modules\Catalog\Http\Resources\ServiceResource;
use App\Modules\Catalog\Services\ServiceCatalogService;
use App\Modules\Core\Support\CrudResponse;
use App\Support\ClinicContext;
use App\Support\FilterHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ServiceController extends Controller
{
    public function __construct(
        private ServiceCatalogService $catalogService,
        private ClinicContext $clinicContext,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Service::class);

        $paginator = $this->catalogService->paginateForActiveClinic();

        $editing = $this->catalogService->findForActiveClinic($request->integer('edit'));

        return Inertia::render('services/Index', [
            'services' => ServiceResource::collection($paginator),
            'query' => FilterHelper::requestState([
                'is_active' => 'boolean',
            ]),
            'currency' => $this->clinicContext->currency(),
            // ?edit=<id> deep link: resolved here so the dialog opens for a row on any page.
            'editing' => CrudResponse::editingProp($request, $editing, ServiceResource::class),
        ]);
    }

    public function create(): RedirectResponse
    {
        $this->authorize('create', Service::class);

        return to_route('services.index', ['new' => 1]);
    }

    public function store(StoreServiceRequest $request): RedirectResponse|JsonResponse
    {
        $this->authorize('create', Service::class);

        $service = $this->catalogService->create($request->validated());

        return CrudResponse::saved(
            $request,
            new ServiceResource($service),
            __('messages.service.created'),
            'services.index',
        );
    }

    public function edit(Service $service): RedirectResponse
    {
        $this->authorize('update', $service);

        return to_route('services.index', ['edit' => $service->id]);
    }

    public function update(UpdateServiceRequest $request, Service $service): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $service);

        $saved = $this->catalogService->update($service, $request->validated());

        return CrudResponse::saved(
            $request,
            new ServiceResource($saved),
            __('messages.service.updated'),
            'services.index',
        );
    }

    public function destroy(Service $service): RedirectResponse
    {
        $this->authorize('delete', $service);

        $this->catalogService->delete($service);

        return CrudResponse::deleted(__('messages.service.deleted'), 'services.index');
    }
}
