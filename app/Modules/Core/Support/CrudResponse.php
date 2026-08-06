<?php

namespace App\Modules\Core\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The shared half of a list-screen CRUD dialog: what a store/update returns, and how the list
 * resolves the row behind a `?edit=<id>` link.
 */
class CrudResponse
{
    /** Query keys that only drive the dialog and must not survive a save. */
    private const MODAL_PARAMS = ['new', 'edit'];

    /**
     * Two callers: the list screen's dialog (Inertia — flash a toast and land back on the list it
     * came from) and a quick-add opened inside another form (plain XHR asking for JSON, which
     * needs the saved row back so it can put it straight into its select).
     */
    public static function saved(
        Request $request,
        JsonResource $resource,
        string $message,
        string $indexRoute,
    ): RedirectResponse|JsonResponse {
        // Inertia's own visits accept text/html and carry the X-Inertia header, so only a caller
        // that explicitly asked for JSON takes this branch.
        if ($request->wantsJson() && ! $request->inertia()) {
            return response()->json(['data' => $resource->resolve()]);
        }

        Toast::success($message);

        return self::backToList($indexRoute);
    }

    /** Same landing rule as a save, for a delete that has nothing to hand back. */
    public static function deleted(string $message, string $indexRoute): RedirectResponse
    {
        Toast::success($message);

        return self::backToList($indexRoute);
    }

    /**
     * The `editing` prop for a `?edit=<id>` deep link: null unless the viewer may actually update
     * that row, which is the same gate the PUT enforces.
     *
     * @param  class-string<JsonResource>  $resourceClass
     * @return array<string, mixed>|null
     */
    public static function editingProp(Request $request, ?Model $row, string $resourceClass): ?array
    {
        if ($row === null || $request->user()?->cannot('update', $row)) {
            return null;
        }

        return (new $resourceClass($row))->resolve();
    }

    /**
     * The list keeps its filters, sort and page in the URL, and Inertia preserves the component
     * across a save — so returning to a bare index would leave the filter controls describing
     * rows the server no longer sent. Go back to the list the request came from, minus the
     * dialog's own params.
     */
    private static function backToList(string $indexRoute): RedirectResponse
    {
        $previous = url()->previous();
        $indexUrl = route($indexRoute);

        if (! str_starts_with($previous, $indexUrl)) {
            return to_route($indexRoute);
        }

        $query = [];
        parse_str((string) parse_url($previous, PHP_URL_QUERY), $query);

        return redirect()->to($indexUrl.self::queryString(
            array_diff_key($query, array_flip(self::MODAL_PARAMS))
        ));
    }

    /** @param  array<string, mixed>  $query */
    private static function queryString(array $query): string
    {
        return $query === [] ? '' : '?'.http_build_query($query);
    }
}
