<?php

namespace App\Http\Controllers;

use App\Modules\Core\Contracts\FollowUpRemindersContract;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(private FollowUpRemindersContract $followUpReminders) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Dashboard', [
            'followUps' => $this->followUpReminders->dueFor($request->user()),
        ]);
    }
}
