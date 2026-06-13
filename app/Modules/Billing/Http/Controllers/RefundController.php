<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Modules\Billing\Http\Requests\RefundTransactionRequest;
use App\Modules\Billing\Services\RefundService;
use App\Modules\Core\Support\Toast;
use Illuminate\Http\RedirectResponse;

class RefundController extends Controller
{
    public function __construct(
        private RefundService $refundService,
    ) {}

    /**
     * POST /transactions/{transaction}/refund
     * Records a refund counter-entry for the given payment.
     */
    public function store(RefundTransactionRequest $request, Transaction $transaction): RedirectResponse
    {
        $this->authorize('refund', $transaction);

        $this->refundService->refund($transaction, $request->validated(), $request->user());

        Toast::success(__('messages.refund.recorded'));

        return redirect()->back();
    }
}
