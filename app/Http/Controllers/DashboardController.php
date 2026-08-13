<?php

namespace App\Http\Controllers;

use App\Modules\Medical\Contracts\FollowUpRemindersContract;
use App\Modules\Scheduling\Contracts\DashboardStatsContract;
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
            'followUpTypes' => $this->followUpReminders->activeTypesFor($user),
            'stats' => $this->dashboardStats->statsFor($user),
        ]);
    }
}
