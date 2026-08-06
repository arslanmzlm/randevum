<?php

namespace App\Modules\Medical\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Modules\Core\Support\CrudResponse;
use App\Modules\Medical\Http\Requests\StoreTagRequest;
use App\Modules\Medical\Http\Requests\UpdateTagRequest;
use App\Modules\Medical\Http\Resources\TagResource;
use App\Modules\Medical\Services\TagService;
use App\Support\FilterHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TagController extends Controller
{
    public function __construct(
        private TagService $service,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Tag::class);

        $editing = $this->service->findForActiveClinic($request->integer('edit'));

        return Inertia::render('tags/Index', [
            'tags' => TagResource::collection($this->service->paginateForActiveClinic()),
            'query' => FilterHelper::requestState(),
            // ?edit=<id> deep link: resolved here so the dialog opens for a row on any page.
            'editing' => CrudResponse::editingProp($request, $editing, TagResource::class),
        ]);
    }

    public function store(StoreTagRequest $request): RedirectResponse|JsonResponse
    {
        $this->authorize('create', Tag::class);

        $tag = $this->service->create($request->validated());

        return CrudResponse::saved(
            $request,
            new TagResource($tag),
            __('messages.tag.created'),
            'tags.index',
        );
    }

    public function update(UpdateTagRequest $request, Tag $tag): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $tag);

        $saved = $this->service->update($tag, $request->validated());

        return CrudResponse::saved(
            $request,
            new TagResource($saved),
            __('messages.tag.updated'),
            'tags.index',
        );
    }

    public function destroy(Tag $tag): RedirectResponse
    {
        $this->authorize('delete', $tag);

        $this->service->delete($tag);

        return CrudResponse::deleted(__('messages.tag.deleted'), 'tags.index');
    }
}
