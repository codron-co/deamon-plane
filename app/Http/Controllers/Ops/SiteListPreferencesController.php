<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Support\Lists\SiteListColumns;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Per-user Sites table layout. Choosing your own columns is not a write to fleet
 * state, so every ops role (viewer included) may save their own.
 */
class SiteListPreferencesController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'columns' => ['sometimes', 'array'],
            'columns.*' => ['string', 'max:32'],
        ]);

        $request->user()?->saveListPreference(SiteListColumns::LIST_KEY, [
            'columns' => SiteListColumns::sanitize($validated['columns'] ?? []),
        ]);

        return back()->with('status', __('sites.columns_picker.saved'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->user()?->saveListPreference(SiteListColumns::LIST_KEY, [
            'columns' => SiteListColumns::defaults(),
            'sort' => [
                'key' => SiteListColumns::DEFAULT_SORT_KEY,
                'dir' => SiteListColumns::DEFAULT_SORT_DIRECTION,
            ],
        ]);

        return back()->with('status', __('sites.columns_picker.reset_done'));
    }
}
