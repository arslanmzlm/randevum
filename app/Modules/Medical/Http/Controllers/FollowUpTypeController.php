<?php

namespace App\Modules\Medical\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\FollowUpType;
use App\Modules\Core\Support\CrudResponse;
use App\Modules\Medical\Http\Requests\StoreFollowUpTypeRequest;
use App\Modules\Medical\Http\Requests\UpdateFollowUpTypeRequest;
use App\Modules\Medical\Http\Resources\FollowUpTypeResource;
use App\Modules\Medical\Services\FollowUpTypeService;
use App\Support\FilterHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FollowUpTypeController extends Controller
{
    public function __construct(
        private FollowUpTypeService $service,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', FollowUpType::class);

        $editing = $this->service->findForActiveClinic($request->integer('edit'));

        return Inertia::render('follow-up-types/Index', [
            'followUpTypes' => FollowUpTypeResource::collection($this->service->paginateForActiveClinic()),
            'query' => FilterHelper::requestState(['is_active' => 'boolean']),
            'editing' => CrudResponse::editingProp($request, $editing, FollowUpTypeResource::class),
        ]);
    }

    public function store(StoreFollowUpTypeRequest $request): RedirectResponse|JsonResponse
    {
        $this->authorize('create', FollowUpType::class);

        $followUpType = $this->service->create($request->validated());

        return CrudResponse::saved(
            $request,
            new FollowUpTypeResource($followUpType),
            __('messages.follow_up_type.created'),
            'follow-up-types.index',
        );
    }

    public function update(UpdateFollowUpTypeRequest $request, FollowUpType $followUpType): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $followUpType);

        $saved = $this->service->update($followUpType, $request->validated());

        return CrudResponse::saved(
            $request,
            new FollowUpTypeResource($saved),
            __('messages.follow_up_type.updated'),
            'follow-up-types.index',
        );
    }

    public function destroy(FollowUpType $followUpType): RedirectResponse
    {
        $this->authorize('delete', $followUpType);

        $this->service->delete($followUpType);

        return CrudResponse::deleted(__('messages.follow_up_type.deleted'), 'follow-up-types.index');
    }
}
