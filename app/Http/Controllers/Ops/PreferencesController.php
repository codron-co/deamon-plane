<?php

namespace App\Http\Controllers\Ops;

use App\Enums\Appearance;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ops\UpdatePreferencesRequest;
use App\Support\OpsAppearance;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Cookie;

class PreferencesController extends Controller
{
    public function show(Request $request): View
    {
        return view('ops.account.preferences', [
            'user' => $request->user(),
            'locales' => config('ops.locales', ['en', 'tr']),
            'appearances' => Appearance::cases(),
        ]);
    }

    public function update(UpdatePreferencesRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->fill($request->validated());
        $user->save();

        $request->session()->put('locale', $user->locale);

        return redirect()
            ->route('ops.account.preferences')
            ->with('status', __('account.flash.preferences_updated'))
            ->withCookie($this->appearanceCookie($user->appearance));
    }

    public function updateLocale(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(config('ops.locales', ['en', 'tr']))],
        ]);

        $user = $request->user();
        $user->locale = $validated['locale'];
        $user->save();
        $request->session()->put('locale', $user->locale);

        return back()->with('status', __('account.flash.locale_updated'));
    }

    public function updateAppearance(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'appearance' => ['required', 'string', Rule::in(Appearance::values())],
        ]);

        $user = $request->user();
        $user->appearance = $validated['appearance'];
        $user->save();

        return back()
            ->with('status', __('account.flash.appearance_updated'))
            ->withCookie($this->appearanceCookie($user->appearance));
    }

    private function appearanceCookie(string $appearance): Cookie
    {
        return cookie(OpsAppearance::COOKIE, $appearance, 60 * 24 * 400, '/', null, false, false, false, 'lax');
    }
}
