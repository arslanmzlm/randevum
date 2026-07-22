<?php

namespace App\Modules\Medical\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PatientSegment;
use App\Modules\Core\Support\Toast;
use App\Modules\Medical\Http\Requests\StoreSegmentRequest;
use App\Modules\Medical\Services\SegmentService;
use Illuminate\Http\RedirectResponse;

class SegmentController extends Controller
{
    public function __construct(
        private SegmentService $service,
    ) {}

    public function store(StoreSegmentRequest $request): RedirectResponse
    {
        $this->authorize('create', PatientSegment::class);

        $this->service->create($request->validated());

        Toast::success(__('messages.segment.saved'));

        return redirect()->back();
    }

    public function destroy(PatientSegment $segment): RedirectResponse
    {
        $this->authorize('delete', $segment);

        $this->service->delete($segment);

        Toast::success(__('messages.segment.deleted'));

        return redirect()->back();
    }
}
