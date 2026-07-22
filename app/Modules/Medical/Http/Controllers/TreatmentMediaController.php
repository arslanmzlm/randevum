<?php

namespace App\Modules\Medical\Http\Controllers;

use App\Enums\TreatmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Treatment;
use App\Modules\Core\Support\Toast;
use App\Modules\Medical\Http\Requests\StoreTreatmentMediaRequest;
use App\Modules\Medical\Services\TreatmentMediaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TreatmentMediaController extends Controller
{
    private const COLLECTION = 'treatment_media';

    public function __construct(
        private TreatmentMediaService $treatmentMediaService,
    ) {}

    /**
     * POST /treatments/{treatment}/media
     */
    public function store(StoreTreatmentMediaRequest $request, Treatment $treatment): RedirectResponse
    {
        $this->authorize('uploadMedia', $treatment);

        // Upload is scoped to the Process (Draft) surface; the UI never offers it on a
        // completed treatment, so reject a direct POST to one at the endpoint too.
        abort_unless($treatment->status === TreatmentStatus::Draft, 403);

        $this->treatmentMediaService->upload(
            $treatment,
            $request->file('file'),
            $request->validated('caption'),
        );

        Toast::success(__('treatment.media.uploaded'));

        return back();
    }

    /**
     * GET /treatments/{treatment}/media/{media}
     * Authorized stream — never a public/getUrl() URL. ?conversion=thumb|medium|large
     * for images (falls back to the original when not yet generated); ?download=1
     * forces an attachment response.
     */
    public function show(Request $request, Treatment $treatment, Media $media): StreamedResponse
    {
        $this->authorize('viewMedia', $treatment);

        $this->assertMediaBelongsToTreatment($treatment, $media);

        if ($request->boolean('download')) {
            return $media->toResponse($request);
        }

        $conversion = (string) $request->query('conversion', '');
        $isImage = str_starts_with((string) $media->mime_type, 'image/');

        if ($isImage && $conversion !== '' && $media->hasGeneratedConversion($conversion)) {
            return $media->toInlineResponse($request, $conversion);
        }

        return $media->toInlineResponse($request);
    }

    /**
     * DELETE /treatments/{treatment}/media/{media}
     */
    public function destroy(Treatment $treatment, Media $media): RedirectResponse
    {
        $this->authorize('deleteMedia', $treatment);

        $this->assertMediaBelongsToTreatment($treatment, $media);

        $this->treatmentMediaService->delete($treatment, $media);

        Toast::success(__('treatment.media.deleted'));

        return back();
    }

    /**
     * {media} has no route-model scoping to {treatment} (Spatie's Media table isn't
     * clinic-scoped), so this is the tenant-isolation guard for show/destroy —
     * mismatch (wrong treatment, wrong collection) 404s rather than 403s.
     *
     * Compares against getMorphClass(), NOT Treatment::class: Treatment is also
     * registered in the app's Relation::morphMap() ('treatment' alias), so Spatie
     * persists that alias to model_type instead of the FQCN.
     */
    private function assertMediaBelongsToTreatment(Treatment $treatment, Media $media): void
    {
        abort_unless(
            $media->model_type === $treatment->getMorphClass()
                && (int) $media->model_id === $treatment->id
                && $media->collection_name === self::COLLECTION,
            404,
        );
    }
}
