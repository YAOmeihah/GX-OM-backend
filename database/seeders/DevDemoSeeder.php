<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

use App\Models\Attachment;
use App\Models\AttachmentUploadIntent;
use App\Models\Invoice;
use App\Models\InvoiceShareToken;
use App\Models\Payment;
use App\Models\RuntimeConfig;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DevDemoSeeder extends Seeder
{
    public function cleanDemoData(): array
    {
        return DB::transaction(function (): array {
            $summary = [];

            $demoUserIds = User::query()
                ->where('email', 'like', 'demo.%@example.com')
                ->pluck('id')
                ->all();

            $demoStoreIds = Store::query()
                ->where('code', 'like', 'DEMO-%')
                ->pluck('id')
                ->all();

            $demoCustomerIds = DB::table('customers')
                ->where('remarks', 'like', 'DEMO:%')
                ->pluck('id')
                ->all();

            $demoInvoiceIds = Invoice::query()
                ->where('invoice_number', 'like', 'DEMO-INV-%')
                ->pluck('id')
                ->all();

            $demoPaymentIds = Payment::query()
                ->where('payment_number', 'like', 'DEMO-PAY-%')
                ->pluck('id')
                ->all();

            $demoShareTokenIds = InvoiceShareToken::query()
                ->where('token', 'like', 'demo-%')
                ->pluck('id')
                ->all();

            $summary['invoice_share_token_logs'] = empty($demoShareTokenIds)
                ? 0
                : DB::table('invoice_share_token_logs')
                    ->whereIn('token_id', $demoShareTokenIds)
                    ->delete();

            $summary['invoice_share_tokens'] = empty($demoShareTokenIds)
                ? 0
                : DB::table('invoice_share_tokens')
                    ->whereIn('id', $demoShareTokenIds)
                    ->delete();

            $summary['attachments'] = DB::table('attachments')
                ->where(function ($query) use ($demoInvoiceIds, $demoPaymentIds, $demoUserIds) {
                    $query->where('file_path', 'like', 'demo/%')
                        ->orWhereIn('uploaded_by', $demoUserIds)
                        ->orWhere(function ($query) use ($demoInvoiceIds) {
                            $query->where('attachable_type', Invoice::class)
                                ->whereIn('attachable_id', $demoInvoiceIds);
                        })
                        ->orWhere(function ($query) use ($demoPaymentIds) {
                            $query->where('attachable_type', Payment::class)
                                ->whereIn('attachable_id', $demoPaymentIds);
                        });
                })
                ->delete();

            $summary['attachment_upload_intents'] = DB::table('attachment_upload_intents')
                ->where(function ($query) use ($demoInvoiceIds, $demoPaymentIds, $demoUserIds) {
                    $query->where('file_path', 'like', 'demo/%')
                        ->orWhereIn('uploaded_by', $demoUserIds)
                        ->orWhere(function ($query) use ($demoInvoiceIds) {
                            $query->where('attachable_type', Invoice::class)
                                ->whereIn('attachable_id', $demoInvoiceIds);
                        })
                        ->orWhere(function ($query) use ($demoPaymentIds) {
                            $query->where('attachable_type', Payment::class)
                                ->whereIn('attachable_id', $demoPaymentIds);
                        });
                })
                ->delete();

            $summary['payment_discounts'] = DB::table('payment_discounts')
                ->where(function ($query) use ($demoPaymentIds, $demoInvoiceIds, $demoUserIds) {
                    $query->where('reason', 'like', 'DEMO:%')
                        ->orWhereIn('payment_id', $demoPaymentIds)
                        ->orWhereIn('invoice_id', $demoInvoiceIds)
                        ->orWhereIn('approved_by', $demoUserIds);
                })
                ->delete();

            $summary['payment_allocations'] = DB::table('payment_allocations')
                ->where(function ($query) use ($demoPaymentIds, $demoInvoiceIds, $demoUserIds) {
                    $query->whereIn('payment_id', $demoPaymentIds)
                        ->orWhereIn('invoice_id', $demoInvoiceIds)
                        ->orWhereIn('allocated_by', $demoUserIds);
                })
                ->delete();

            $summary['invoice_items'] = DB::table('invoice_items')
                ->where(function ($query) use ($demoInvoiceIds) {
                    $query->where('item_description', 'like', 'DEMO:%')
                        ->orWhere('item_name', 'like', 'DEMO%')
                        ->orWhereIn('invoice_id', $demoInvoiceIds);
                })
                ->delete();

            $summary['customer_store_stats'] = DB::table('customer_store_stats')
                ->where(function ($query) use ($demoCustomerIds, $demoStoreIds) {
                    $query->whereIn('customer_id', $demoCustomerIds)
                        ->orWhereIn('store_id', $demoStoreIds);
                })
                ->delete();

            $summary['payments'] = empty($demoPaymentIds)
                ? 0
                : DB::table('payments')
                    ->whereIn('id', $demoPaymentIds)
                    ->delete();

            $summary['invoices'] = empty($demoInvoiceIds)
                ? 0
                : DB::table('invoices')
                    ->whereIn('id', $demoInvoiceIds)
                    ->delete();

            $summary['customers'] = empty($demoCustomerIds)
                ? 0
                : DB::table('customers')
                    ->whereIn('id', $demoCustomerIds)
                    ->delete();

            $summary['store_user'] = DB::table('store_user')
                ->where(function ($query) use ($demoStoreIds, $demoUserIds) {
                    $query->whereIn('store_id', $demoStoreIds)
                        ->orWhereIn('user_id', $demoUserIds);
                })
                ->delete();

            $summary['role_user'] = empty($demoUserIds)
                ? 0
                : DB::table('role_user')
                    ->whereIn('user_id', $demoUserIds)
                    ->delete();

            $summary['users'] = empty($demoUserIds)
                ? 0
                : DB::table('users')
                    ->whereIn('id', $demoUserIds)
                    ->delete();

            $summary['stores'] = empty($demoStoreIds)
                ? 0
                : DB::table('stores')
                    ->whereIn('id', $demoStoreIds)
                    ->delete();

            $demoRuntimeConfigIds = RuntimeConfig::query()
                ->get(['id', 'key', 'value'])
                ->filter(function (RuntimeConfig $runtimeConfig): bool {
                    return str_starts_with($runtimeConfig->key, 'demo.')
                        || (is_array($runtimeConfig->value) && (($runtimeConfig->value['demo_seeded'] ?? false) === true));
                })
                ->pluck('id')
                ->all();

            $summary['runtime_configs'] = empty($demoRuntimeConfigIds)
                ? 0
                : DB::table('runtime_configs')
                    ->whereIn('id', $demoRuntimeConfigIds)
                    ->delete();

            return $summary;
        });
    }

    public function seedDemoData(): array
    {
        return ['seeded' => 0];
    }
}
