<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Modules\Billing\Http\Requests\RecordPaymentRequest;
use App\Modules\Billing\Services\PaymentService;
use App\Modules\Core\Support\Toast;
use Illuminate\Http\RedirectResponse;

class PaymentController extends Controller
{
    public function __construct(
        private PaymentService $paymentService,
    ) {}

    /**
     * POST /payments
     * Records a standalone or treatment-bound payment for a patient.
     */
    public function store(RecordPaymentRequest $request): RedirectResponse
    {
        $this->authorize('create', Transaction::class);

        $this->paymentService->record($request->validated(), $request->user());

        Toast::success(__('messages.payment.recorded'));

        return redirect()->back();
    }
}
