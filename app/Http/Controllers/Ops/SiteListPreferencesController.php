<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Support\Lists\SiteListColumns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
        ]);

        return $this->respond($request, $columns, __('sites.columns_picker.reset_done'));
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
