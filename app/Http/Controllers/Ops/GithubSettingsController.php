<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GithubSettingsController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $this->authorize('ops.write');

        return redirect()
            ->route('ops.themes')
            ->with('status', __('settings.github.moved'));
    }

    public function testConnection(Request $request): RedirectResponse
    {
        $this->authorize('ops.write');

        return redirect()
            ->route('ops.themes')
            ->with('status', __('settings.github.moved'));
    }
}
