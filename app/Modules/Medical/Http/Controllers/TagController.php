<?php

namespace App\Modules\Medical\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Modules\Core\Support\Toast;
use App\Modules\Medical\Http\Requests\StoreTagRequest;
use App\Modules\Medical\Http\Requests\UpdateTagRequest;
use App\Modules\Medical\Http\Resources\TagResource;
use App\Modules\Medical\Services\TagService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TagController extends Controller
{
    public function __construct(
        private TagService $service,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', Tag::class);

        return Inertia::render('tags/Index', [
            'tags' => TagResource::collection($this->service->listForActiveClinic()),
        ]);
    }

    public function store(StoreTagRequest $request): RedirectResponse
    {
        $this->authorize('create', Tag::class);

        $this->service->create($request->validated());

        Toast::success(__('messages.tag.created'));

        return to_route('tags.index');
    }

    public function update(UpdateTagRequest $request, Tag $tag): RedirectResponse
    {
        $this->authorize('update', $tag);

        $this->service->update($tag, $request->validated());

        Toast::success(__('messages.tag.updated'));

        return to_route('tags.index');
    }

    public function destroy(Tag $tag): RedirectResponse
    {
        $this->authorize('delete', $tag);

        $this->service->delete($tag);

        Toast::success(__('messages.tag.deleted'));

        return to_route('tags.index');
    }
}
