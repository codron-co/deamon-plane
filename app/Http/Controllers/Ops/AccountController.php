<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ops\UpdateAccountAvatarRequest;
use App\Http\Requests\Ops\UpdateAccountPasswordRequest;
use App\Http\Requests\Ops\UpdateAccountProfileRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AccountController extends Controller
{
    public function show(Request $request): View
    {
        return view('ops.account.show', [
            'user' => $request->user(),
        ]);
    }

    public function update(UpdateAccountProfileRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->fill($request->validated());
        $user->save();

        return redirect()
            ->route('ops.account.show')
            ->with('status', __('account.flash.profile_updated'));
    }

    public function updatePassword(UpdateAccountPasswordRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->password = $request->validated('password');
        $user->save();

        return redirect()
            ->route('ops.account.show')
            ->with('status', __('account.flash.password_updated'));
    }

    public function updateAvatar(UpdateAccountAvatarRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $file = $request->file('avatar');

        $this->deleteAvatar($user);

        $path = $file->store('avatars', 'public');
        $user->avatar_path = $path;
        $user->save();

        return redirect()
            ->route('ops.account.show')
            ->with('status', __('account.flash.avatar_updated'));
    }

    public function destroyAvatar(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->deleteAvatar($user);
        $user->avatar_path = null;
        $user->save();

        return redirect()
            ->route('ops.account.show')
            ->with('status', __('account.flash.avatar_removed'));
    }

    private function deleteAvatar(User $user): void
    {
        if (filled($user->avatar_path)) {
            Storage::disk('public')->delete((string) $user->avatar_path);
        }
    }
}
