<?php

namespace App\Modules\Messaging\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SmsLog;
use App\Modules\Messaging\Http\Resources\SmsLogResource;
use App\Modules\Messaging\Services\SmsLogService;
use App\Support\FilterHelper;
use Inertia\Inertia;
use Inertia\Response;

class SmsLogController extends Controller
{
    public function __construct(private SmsLogService $service) {}

    public function index(): Response
    {
        $this->authorize('viewAny', SmsLog::class);

        $paginator = $this->service->listForActiveClinic();

        return Inertia::render('sms-logs/Index', [
            'smsLogs' => SmsLogResource::collection($paginator),
            'query' => FilterHelper::requestState([
                'status' => 'string',
                'type' => 'string',
                'start_date' => 'string',
                'end_date' => 'string',
            ]),
        ]);
    }
}
