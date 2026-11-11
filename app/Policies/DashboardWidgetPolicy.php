<?php

namespace App\Policies;

use App\Models\DashboardWidget;
use App\Models\FinancialProfile;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Dashboards are per member: a widget is only reachable by its owner,
 * through the profile it is configured for — anything else is a 404.
 */
class DashboardWidgetPolicy
{
    public function __construct(private readonly FinancialProfilePolicy $profiles) {}

    public function view(User $user, DashboardWidget $widget, FinancialProfile $profile): Response
    {
        $visible = $this->profiles->view($user, $profile);

        if ($visible->denied()) {
            return $visible;
        }

        return $widget->isOwnedBy($user->id, $profile->id) ? Response::allow() : Response::denyAsNotFound();
    }

    public function delete(User $user, DashboardWidget $widget, FinancialProfile $profile): Response
    {
        return $this->view($user, $widget, $profile);
    }
}
