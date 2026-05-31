<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\Service;
use App\Modules\Catalog\Http\Requests\StoreServiceRequest;
use App\Modules\Catalog\Http\Requests\UpdateServiceRequest;
use App\Modules\Catalog\Http\Resources\ServiceResource;
use App\Modules\Catalog\Services\ServiceCatalogService;
use App\Modules\Core\Support\Toast;
use App\Support\ClinicContext;
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

        return Inertia::render('services/Index', [
            'services' => $this->catalogService->listForActiveClinic()->map(fn (Service $s) => (new ServiceResource($s))->resolve()),
            'canManage' => $request->user()->can('services.create'),
            'currency' => $this->activeClinicCurrency(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Service::class);

        return Inertia::render('services/Create', [
            'currency' => $this->activeClinicCurrency(),
        ]);
    }

    public function store(StoreServiceRequest $request): RedirectResponse
    {
        $this->authorize('create', Service::class);

        $this->catalogService->create($request->validated());

        Toast::success(__('messages.service.created'));

        return redirect()->route('services.index');
    }

    public function edit(Service $service): Response
    {
        $this->authorize('update', $service);

        return Inertia::render('services/Edit', [
            'service' => (new ServiceResource($service))->resolve(),
            'currency' => $this->activeClinicCurrency(),
        ]);
    }

    public function update(UpdateServiceRequest $request, Service $service): RedirectResponse
    {
        $this->authorize('update', $service);

        $this->catalogService->update($service, $request->validated());

        Toast::success(__('messages.service.updated'));

        return redirect()->route('services.index');
    }

    public function destroy(Service $service): RedirectResponse
    {
        $this->authorize('delete', $service);

        $this->catalogService->delete($service);

        Toast::success(__('messages.service.deleted'));

        return redirect()->route('services.index');
    }

    private function activeClinicCurrency(): string
    {
        $clinic = Clinic::find($this->clinicContext->id());

        return $clinic?->currency ?? 'TRY';
    }
}
