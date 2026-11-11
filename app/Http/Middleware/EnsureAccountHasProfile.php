<?php

namespace App\Http\Middleware;

use App\Models\FinancialProfileInvite;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Self-healing for the invite grant on email verification
 * (docs/shared-finances.md — invites): accounts registered through an invite
 * have no profile until the `Verified` listener grants the reserved
 * membership. If that listener failed (the account is verified by then and
 * the event never fires again), the next authenticated request completes
 * the grant — or seeds a default profile — so a verified account never
 * stays without a profile.
 */
class EnsureAccountHasProfile
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->hasVerifiedEmail() && ! $user->profiles()->exists()) {
            FinancialProfileInvite::completePendingFor($user);
        }

        return $next($request);
    }
}
