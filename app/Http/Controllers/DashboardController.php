<?php

namespace App\Http\Controllers;

use App\Modules\Core\Contracts\DashboardStatsContract;
use App\Modules\Core\Contracts\FollowUpRemindersContract;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private FollowUpRemindersContract $followUpReminders,
        private DashboardStatsContract $dashboardStats,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Dashboard', [
            'followUps' => $this->followUpReminders->dueFor($user),
            'stats' => $this->dashboardStats->statsFor($user),
        ]);
    }
}
