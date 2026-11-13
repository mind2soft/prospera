<?php

namespace App\Http\Controllers;

use App\Services\OnboardingProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    public function __construct(private readonly OnboardingProgress $progress) {}

    /** Send a first-time user through the short getting-started guide. */
    public function dashboard(Request $request): Response|RedirectResponse
    {
        if ($request->user()->onboarding_seen_at === null) {
            return to_route('onboarding');
        }

        return Inertia::render('Dashboard');
    }

    public function show(Request $request): Response
    {
        if ($request->user()->onboarding_seen_at === null) {
            $request->user()->forceFill(['onboarding_seen_at' => now()])->save();
        }

        return Inertia::render('Onboarding');
    }

    public function help(string $topic): Response
    {
        abort_unless(in_array($topic, ['envelopes', 'ledgers', 'transactions', 'budgets'], true), 404);

        return Inertia::render('Help', ['topic' => $topic]);
    }

    public function progress(Request $request): JsonResponse
    {
        $profile = $this->authorizeProfileId((int) $request->query('profile_id'), 'manage');

        return response()->json(['progress' => $this->progress->forProfile($profile)]);
    }
}
