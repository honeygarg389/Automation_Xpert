<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentTransaction;
use App\Services\AuditLogService;
use App\Services\Billing\BillingGatewayRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function __construct(
        private BillingGatewayRegistry $gateways,
        private AuditLogService $auditLog
    ) {}

    public function index(): Response
    {
        $transactions = PaymentTransaction::with(['user', 'subscription.plan'])
            ->latest()
            ->paginate(25);

        return Inertia::render('Admin/Transactions/Index', [
            'transactions' => $transactions,
        ]);
    }

    public function refund(Request $request, PaymentTransaction $transaction): RedirectResponse
    {
        $validated = $request->validate([
            'amount_cents' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        if ($transaction->refunded_at) {
            return back()->with('error', __('Transaction has already been refunded.'));
        }

        $gateway = $this->gateways->get($transaction->gateway ?? 'stripe');
        if (! $gateway) {
            return back()->with('error', __('Gateway not configured.'));
        }

        $result = $gateway->refund($transaction, $validated['amount_cents'] ?? null);

        if (! $result['ok']) {
            return back()->with('error', $result['error'] ?? __('Refund failed.'));
        }

        if ($validated['reason'] ?? null) {
            $transaction->update(['refund_reason' => $validated['reason']]);
        }

        // Money leaving the system was previously unrecorded: this controller
        // had no audit logging at all, so "who authorised this refund" was
        // unanswerable. Matches the shape used for impersonation.started and
        // client.plan_assigned rather than inventing a new one.
        $this->auditLog->logAdmin('payment.refunded', PaymentTransaction::class, (int) $transaction->id, [
            'amount_cents' => $validated['amount_cents'] ?? $transaction->amount_cents,
            'full_refund' => ($validated['amount_cents'] ?? null) === null,
            'reason' => $validated['reason'] ?? null,
            'gateway' => $transaction->gateway,
            'gateway_result' => $result['id'] ?? ($result['reference'] ?? null),
        ]);

        return back()->with('success', __('Refund processed.'));
    }
}
