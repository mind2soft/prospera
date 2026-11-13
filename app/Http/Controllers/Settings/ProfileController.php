<?php

namespace App\Http\Controllers\Settings;

use App\Actions\DeleteAccount;
use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\FinancialProfileInvite;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $previousEmail = $user->email;

        $user->fill($request->validated());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        DB::transaction(function () use ($user, $previousEmail): void {
            $user->save();

            // An invite reserved at registration only grants to its own
            // address: once the account moves to another one, the
            // reservation is released, so the owner's pending list stops
            // showing it as awaiting verification and the original address
            // can be re-invited (WF-R3-2).
            if (! FinancialProfileInvite::sameEmail($previousEmail, $user->email)) {
                FinancialProfileInvite::releaseReservationOf($user);
            }
        });

        // A changed preferred language applies to this response already.
        App::setLocale(SetLocale::resolve($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's account (see DeleteAccount for the rules).
     */
    public function destroy(ProfileDeleteRequest $request, DeleteAccount $deleteAccount): RedirectResponse
    {
        // Log out only once the deletion has committed: a failed deletion
        // must not leave the user signed out with their account intact.
        $deleteAccount->handle($request->user());

        // logoutCurrentDevice() (unlike logout()) does not cycle the remember
        // token, which would save — i.e. re-insert — the deleted user.
        Auth::guard('web')->logoutCurrentDevice();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
