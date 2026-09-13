<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Support\Lists\SiteListColumns;
use App\Support\Lists\SiteSavedViews;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Per-user Sites table layout. Choosing your own columns is not a write to fleet
 * state, so every ops role (viewer included) may save their own.
 */
class SiteListPreferencesController extends Controller
{
    public function update(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'columns' => ['sometimes', 'array'],
            'columns.*' => ['string', 'max:32'],
        ]);

        $columns = SiteListColumns::sanitize($validated['columns'] ?? []);

        $request->user()?->saveListPreference(SiteListColumns::LIST_KEY, [
            'columns' => $columns,
        ]);

        return $this->respond($request, $columns, __('sites.columns_picker.saved'));
    }

    public function destroy(Request $request): RedirectResponse|JsonResponse
    {
        $columns = SiteListColumns::defaults();

        $request->user()?->saveListPreference(SiteListColumns::LIST_KEY, [
            'columns' => $columns,
            'sort' => [
                'key' => SiteListColumns::DEFAULT_SORT_KEY,
                'dir' => SiteListColumns::DEFAULT_SORT_DIRECTION,
            ],
            // Keep the current view "already applied" so reset is not undone
            // by rememberColumns on the next render of the same ?view=.
            'applied_view' => $this->activeSavedViewId($request),
        ]);

        return $this->respond($request, $columns, __('sites.columns_picker.reset_done'));
    }

    public function storeView(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'default' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'channel' => ['sometimes', 'nullable', 'string', 'max:32'],
            'status' => ['sometimes', 'nullable', 'string', 'max:32'],
            'publish' => ['sometimes', 'nullable', 'string', 'max:32'],
            'deploy' => ['sometimes', 'nullable', 'string', 'max:32'],
            'agent' => ['sometimes', 'nullable', 'string', 'max:32'],
            'pack' => ['sometimes', 'nullable', 'string', 'max:32'],
            'health' => ['sometimes', 'nullable', 'string', 'max:32'],
            'app' => ['sometimes', 'nullable', 'string', 'max:32'],
            'columns' => ['sometimes', 'array'],
            'columns.*' => ['string', 'max:32'],
            'sort_key' => ['sometimes', 'nullable', 'string', 'max:32'],
            'sort_dir' => ['sometimes', 'nullable', 'string', 'in:asc,desc'],
        ]);

        $user = $request->user();
        if ($user === null) {
            abort(403);
        }

        try {
            $view = SiteSavedViews::store(
                $user,
                (string) $validated['name'],
                $validated,
                $validated['columns'] ?? [],
                (string) ($validated['sort_key'] ?? ''),
                (string) ($validated['sort_dir'] ?? ''),
                $request->boolean('default'),
            );
        } catch (InvalidArgumentException $exception) {
            $message = $exception->getMessage() === 'limit'
                ? __('sites.saved_views.limit', ['max' => SiteSavedViews::MAX])
                : __('sites.saved_views.empty_name');

            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => $message,
                ], 422);
            }

            return back()->with('error', $message);
        }

        $url = route('ops.sites', SiteSavedViews::savedQuery($view));

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => __('sites.saved_views.saved'),
                'list' => SiteListColumns::LIST_KEY,
                'columns' => $view['columns'],
                'refresh_list' => true,
                'redirect' => $url,
            ]);
        }

        return redirect()->to($url)->with('status', __('sites.saved_views.saved'));
    }

    public function destroyView(Request $request, string $view): RedirectResponse|JsonResponse
    {
        if (! preg_match('/^[a-z0-9]{8,26}$/', $view)) {
            abort(404);
        }

        $user = $request->user();
        if ($user === null) {
            abort(403);
        }

        SiteSavedViews::forget($user, $view);
        $columns = SiteListColumns::sanitize(
            $user->fresh()?->listPreference(SiteListColumns::LIST_KEY)['columns'] ?? SiteListColumns::defaults(),
        );

        return $this->respond($request, $columns, __('sites.saved_views.deleted'));
    }

    private function activeSavedViewId(Request $request): ?string
    {
        $id = trim((string) $request->query('view', ''));
        if ($id === '' || $id === SiteSavedViews::ALL) {
            return null;
        }

        $saved = SiteSavedViews::resolve($request, $request->user())->saved;
        foreach ($saved as $row) {
            if ($row['id'] === $id) {
                return $id;
            }
        }

        return null;
    }

    /**
     * The saved layout goes back in the response so the picker and the table can
     * both catch up without the operator reloading the page.
     *
     * @param  list<string>  $columns
     */
    private function respond(Request $request, array $columns, string $message): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $message,
                'list' => SiteListColumns::LIST_KEY,
                'columns' => $columns,
                'refresh_list' => true,
            ]);
        }

        return back()->with('status', $message);
    }
}
