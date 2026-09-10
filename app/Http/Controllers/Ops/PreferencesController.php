<?php

namespace App\Http\Controllers\Ops;

use App\Enums\Appearance;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ops\UpdatePreferencesRequest;
use App\Support\OpsAppearance;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
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

        $request->session()->put('locale', $user->localeValue());

        return redirect()
            ->route('ops.account.preferences')
            ->with('status', __('account.flash.preferences_updated'))
            ->withCookie($this->appearanceCookie($user->appearanceValue()));
    }

    public function updateLocale(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(config('ops.locales', ['en', 'tr']))],
        ]);

        $user = $request->user();
        $user->locale = $validated['locale'];
        $user->save();
        $request->session()->put('locale', $user->localeValue());

        return $this->preferenceResponse(
            $request,
            __('account.flash.locale_updated'),
            [
                'locale' => $user->localeValue(),
                'label' => __('ops.locale.'.$user->localeValue()),
                'reload' => true,
            ],
        );
    }

    public function updateAppearance(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'appearance' => ['required', 'string', Rule::in(Appearance::values())],
        ]);

        $user = $request->user();
        $user->appearance = Appearance::from($validated['appearance']);
        $user->save();

        $value = $user->appearanceValue();

        return $this->preferenceResponse(
            $request,
            __('account.flash.appearance_updated'),
            [
                'appearance' => $value,
                'label' => __('account.appearance_modes.'.$value),
                'reload' => false,
            ],
            $this->appearanceCookie($value),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function preferenceResponse(
        Request $request,
        string $flash,
        array $payload,
        ?Cookie $cookie = null,
    ): RedirectResponse|JsonResponse {
        if ($request->expectsJson()) {
            $response = response()->json($payload);
            if ($cookie) {
                $response->withCookie($cookie);
            }

            return $response;
        }

        $redirect = back()->with('status', $flash);

        return $cookie ? $redirect->withCookie($cookie) : $redirect;
    }

    private function appearanceCookie(string $appearance): Cookie
    {
        return cookie(OpsAppearance::COOKIE, $appearance, 60 * 24 * 400, '/', null, false, false, false, 'lax');
    }
}
