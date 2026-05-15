<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentDiscount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MaintenanceCleanupService
{
    public function __construct(private readonly CustomerStatsService $customerStatsService) {}

    public function deleteInvoice(Invoice $invoice, array &$deleted): void
    {
        DB::transaction(function () use ($invoice, &$deleted): void {
            $customerId = $invoice->customer_id;
            $storeId = $invoice->store_id;

            $deleted['invoice_items'] += InvoiceItem::where('invoice_id', $invoice->id)->delete();

            PaymentAllocation::where('invoice_id', $invoice->id)->get()->each(function (PaymentAllocation $allocation) use (&$deleted): void {
                $payment = Payment::whereKey($allocation->payment_id)->lockForUpdate()->first();

                if ($payment) {
                    $payment->allocated_amount = max(0, (float) $payment->allocated_amount - (float) $allocation->amount);
                    $payment->saveQuietly();
                }

                $allocation->delete();
                $deleted['payment_allocations']++;
            });

            $deleted['payment_discounts'] += PaymentDiscount::where('invoice_id', $invoice->id)->count();
            PaymentDiscount::where('invoice_id', $invoice->id)->delete();

            $this->deleteAttachments(Invoice::class, $invoice->id, $deleted);

            $invoice->delete();
            $deleted['invoices']++;

            $this->customerStatsService->syncCustomerStoreStats($customerId, $storeId);
        });
    }

    public function deletePayment(Payment $payment, array &$deleted): void
    {
        DB::transaction(function () use ($payment, &$deleted): void {
            $allocationInvoiceIds = PaymentAllocation::where('payment_id', $payment->id)
                ->pluck('invoice_id')
                ->map(fn ($invoiceId) => (int) $invoiceId)
                ->all();

            $payment->revokeAllAllocations(0);

            $discountInvoiceIds = PaymentDiscount::where('payment_id', $payment->id)
                ->pluck('invoice_id')
                ->map(fn ($invoiceId) => (int) $invoiceId)
                ->all();

            $deleted['payment_discounts'] += PaymentDiscount::where('payment_id', $payment->id)->count();
            PaymentDiscount::where('payment_id', $payment->id)->delete();

            $this->deleteAttachments(Payment::class, $payment->id, $deleted);

            $payment->delete();
            $deleted['payments']++;

            $this->refreshInvoices(array_values(array_unique(array_merge($allocationInvoiceIds, $discountInvoiceIds))));
        });
    }

    public function deletePaymentAllocation(PaymentAllocation $allocation, array &$deleted): void
    {
        DB::transaction(function () use ($allocation, &$deleted): void {
            $payment = Payment::whereKey($allocation->payment_id)->lockForUpdate()->first();

            if ($payment) {
                $payment->revokeAllocation($allocation, 0);
            } else {
                $invoice = Invoice::whereKey($allocation->invoice_id)->lockForUpdate()->first();

                if ($invoice) {
                    $invoice->paid_amount = max(0, (float) $invoice->paid_amount - (float) $allocation->amount);
                    $invoice->updateStatus();
                }

                $allocation->delete();
            }

            $deleted['payment_allocations']++;
        });
    }

    public function deleteAttachment(Attachment $attachment, array &$deleted): void
    {
        $this->deleteStorageFile($attachment);
        $attachment->delete();
        $deleted['attachments']++;
    }

    public function deleteAttachments(string $attachableType, int $attachableId, array &$deleted): void
    {
        Attachment::where('attachable_type', $attachableType)
            ->where('attachable_id', $attachableId)
            ->get()
            ->each(fn (Attachment $attachment) => $this->deleteAttachment($attachment, $deleted));
    }

    public function refreshInvoices(array $invoiceIds): void
    {
        Invoice::whereIn('id', array_values(array_unique($invoiceIds)))
            ->get()
            ->each(function (Invoice $invoice): void {
                $invoice->updateStatus();
            });
    }

    private function deleteStorageFile(Attachment $attachment): void
    {
        if ($attachment->disk && $attachment->path && Storage::disk($attachment->disk)->exists($attachment->path)) {
            Storage::disk($attachment->disk)->delete($attachment->path);
        }
    }
}
