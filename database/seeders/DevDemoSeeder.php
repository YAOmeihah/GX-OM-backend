<?php

namespace Database\Seeders;

use App\Models\Attachment;
use App\Models\AttachmentUploadIntent;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerStoreStat;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceShareToken;
use App\Models\InvoiceShareTokenLog;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentDiscount;
use App\Models\Role;
use App\Models\RuntimeConfig;
use App\Models\Store;
use App\Models\User;
use App\Services\CustomerStatsService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DevDemoSeeder extends Seeder
{
    private const DEMO_DATE = '20260518';

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

            $summary['audit_logs'] = DB::table('audit_logs')
                ->where(function ($query) use ($demoStoreIds, $demoUserIds) {
                    $query->where('description', 'like', 'DEMO:%')
                        ->orWhereIn('user_id', $demoUserIds)
                        ->orWhereIn('business_store_id', $demoStoreIds)
                        ->orWhereIn('actor_store_id', $demoStoreIds);
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
        return DB::transaction(function (): array {
            return $this->withoutDemoAuditing(function (): array {
                $this->call(DatabaseSeeder::class);

                $summary = [];

                $storeSeed = $this->seedStores();
                $stores = $storeSeed['models'];
                $summary = array_merge($summary, $storeSeed['summary']);

                $userSeed = $this->seedUsers($stores);
                $users = $userSeed['models'];
                $summary = array_merge($summary, $userSeed['summary']);

                $customerSeed = $this->seedCustomers($stores);
                $customers = $customerSeed['models'];
                $summary = array_merge($summary, $customerSeed['summary']);

                $invoiceSeed = $this->seedInvoicesAndItems($stores, $users, $customers);
                $invoices = $invoiceSeed['models'];
                $summary = array_merge($summary, $invoiceSeed['summary']);

                $paymentSeed = $this->seedPaymentsAndAllocations($stores, $users, $customers, $invoices);
                $payments = $paymentSeed['models'];
                $summary = array_merge($summary, $paymentSeed['summary']);

                $discountSeed = $this->seedDiscounts($users, $payments, $invoices);
                $summary = array_merge($summary, $discountSeed['summary']);

                $shareSeed = $this->seedShareTokens($users, $stores, $customers, $invoices);
                $summary = array_merge($summary, $shareSeed['summary']);

                $attachmentSeed = $this->seedAttachments($users, $invoices, $payments);
                $summary = array_merge($summary, $attachmentSeed['summary']);

                $runtimeConfigSeed = $this->seedRuntimeConfigIfMissing();
                $summary = array_merge($summary, $runtimeConfigSeed['summary']);

                $auditSeed = $this->seedAuditLogs($users, $stores, $customers, $invoices, $payments);
                $summary = array_merge($summary, $auditSeed['summary']);

                $maintenanceSeed = $this->seedMaintenanceScenarios($users, $customers, $stores, $invoices, $payments);
                $summary = array_merge($summary, $maintenanceSeed['summary']);

                $statsSeed = $this->syncStats($customers, $stores);
                $summary = array_merge($summary, $statsSeed['summary']);

                return $summary;
            });
        });
    }

    /**
     * Seed the three demo stores.
     *
     * @return array{models: array<string, Store>, summary: array<string, int>}
     */
    private function seedStores(): array
    {
        $stores = [];

        foreach ($this->storeDefinitions() as $key => $definition) {
            $stores[$key] = Store::create($definition);
        }

        return [
            'models' => $stores,
            'summary' => ['stores' => count($stores)],
        ];
    }

    /**
     * Seed the six demo users and assign roles / stores.
     *
     * @param  array<string, Store>  $stores
     * @return array{models: array<string, User>, summary: array<string, int>}
     */
    private function seedUsers(array $stores): array
    {
        $roles = Role::whereIn('slug', ['admin', 'store_owner', 'store_staff'])->get()->keyBy('slug');

        $password = 'password';
        $users = [];

        foreach ($this->userDefinitions() as $key => $definition) {
            $storeKeys = $definition['stores'] ?? [];
            $roleSlugs = $definition['role_slugs'] ?? [];
            unset($definition['stores'], $definition['role_slugs']);

            $users[$key] = User::create($definition + ['password' => $password]);

            $users[$key]->roles()->sync(array_map(
                fn (string $roleSlug): int => $roles[$roleSlug]->id,
                $roleSlugs
            ));

            $storeIds = array_map(
                fn (string $storeKey): int => $stores[$storeKey]->id,
                $storeKeys
            );

            if ($storeIds !== []) {
                $users[$key]->stores()->sync($storeIds);
            }
        }

        return [
            'models' => $users,
            'summary' => ['users' => count($users)],
        ];
    }

    /**
     * Seed the fifteen demo customers.
     *
     * @param  array<string, Store>  $stores
     * @return array{models: array<string, Customer>, summary: array<string, int>}
     */
    private function seedCustomers(array $stores): array
    {
        $customers = [];

        foreach ($this->customerDefinitions() as $key => $definition) {
            $storeKey = $definition['store'];
            unset($definition['store']);

            $customers[$key] = Customer::create([
                'store_id' => $stores[$storeKey]->id,
                'name' => $definition['name'],
                'phone' => $definition['phone'],
                'email' => $definition['email'],
                'address' => $definition['address'],
                'id_card' => sprintf('11010119900101%04d', count($customers) + 1),
                'remarks' => $definition['remarks'],
            ]);
        }

        return [
            'models' => $customers,
            'summary' => ['customers' => count($customers)],
        ];
    }

    /**
     * Seed invoices and invoice items.
     *
     * @param  array<string, Store>  $stores
     * @param  array<string, User>  $users
     * @param  array<int, Customer>  $customers
     * @return array{models: array<string, Invoice>, summary: array<string, int>}
     */
    private function seedInvoicesAndItems(array $stores, array $users, array $customers): array
    {
        $invoices = [];
        $itemCount = 0;
        $sequence = 1;
        $customerDefinitions = $this->customerDefinitions();

        foreach ($customers as $customerKey => $customer) {
            $storeKey = $customerDefinitions[$customerKey]['store'];
            $creator = $users[$this->creatorKeyForStore($storeKey)];

            for ($slot = 1; $slot <= 3; $slot++) {
                $amount = 300 + ($sequence * 20);
                $invoiceNumber = sprintf('DEMO-INV-%s-%03d', self::DEMO_DATE, $sequence);

                $invoice = Invoice::create([
                    'invoice_number' => $invoiceNumber,
                    'store_id' => $stores[$storeKey]->id,
                    'customer_id' => $customer->id,
                    'created_by' => $creator->id,
                    'amount' => $amount,
                    'paid_amount' => 0,
                    'due_date' => $this->demoInvoiceDueDate($slot, $sequence),
                    'status' => 'unpaid',
                    'description' => "DEMO: {$customerKey} invoice {$slot}",
                ]);

                $invoice->forceFill([
                    'created_at' => $this->demoInvoiceCreatedAt($slot, $sequence),
                    'updated_at' => $this->demoInvoiceCreatedAt($slot, $sequence),
                ])->saveQuietly();

                foreach ([1, 2] as $itemIndex) {
                    InvoiceItem::create([
                        'invoice_id' => $invoice->id,
                        'item_name' => "DEMO 商品 {$sequence}-{$itemIndex}",
                        'item_description' => "DEMO: {$invoiceNumber} 明细 {$itemIndex}",
                        'quantity' => 1,
                        'unit_price' => $amount / 2,
                        'subtotal' => $amount / 2,
                        'sort_order' => $itemIndex,
                    ]);

                    $itemCount++;
                }

                $invoice->refresh()->updateStatus();
                $invoices["{$customerKey}_{$slot}"] = $invoice->refresh();
                $sequence++;
            }
        }

        return [
            'models' => $invoices,
            'summary' => [
                'invoices' => count($invoices),
                'invoice_items' => $itemCount,
            ],
        ];
    }

    /**
     * Seed payments and allocations.
     *
     * @param  array<string, Store>  $stores
     * @param  array<string, User>  $users
     * @param  array<int, Customer>  $customers
     * @param  array<string, Invoice>  $invoices
     * @return array{models: array<string, Payment>, summary: array<string, int>}
     */
    private function seedPaymentsAndAllocations(array $stores, array $users, array $customers, array $invoices): array
    {
        $payments = [];
        $allocationCount = 0;

        foreach ($this->paymentDefinitions() as $sequence => $definition) {
            $customer = $customers[$definition['customer']];
            $storeKey = $this->customerDefinitions()[$definition['customer']]['store'];
            $receivedBy = $users[$definition['user'] ?? $this->receiverKeyForStore($storeKey)];
            $allocations = $definition['allocations'] ?? [];
            $amount = $definition['amount'] ?? $this->allocationSum($allocations, $invoices);
            $amount += $definition['extra'] ?? 0;

            $payment = Payment::create([
                'payment_number' => sprintf('DEMO-PAY-%s-%03d', self::DEMO_DATE, $sequence + 1),
                'store_id' => $stores[$storeKey]->id,
                'customer_id' => $customer->id,
                'received_by' => $receivedBy->id,
                'amount' => round($amount, 2),
                'allocated_amount' => 0,
                'payment_method' => $definition['method'],
                'reference_number' => sprintf('DEMO-REF-%03d', $sequence + 1),
                'remarks' => $definition['remarks'] ?? 'DEMO: local payment fixture',
            ]);

            foreach ($allocations as $allocation) {
                $invoice = $invoices[$allocation['invoice']];
                $payment->allocateToInvoice(
                    $invoice,
                    $this->resolveAllocationAmount($invoice, $allocation['amount']),
                    $receivedBy->id
                );
                $payment->refresh();
                $allocationCount++;
            }

            $payments[$definition['key']] = $payment->refresh();
        }

        return [
            'models' => $payments,
            'summary' => [
                'payments' => count($payments),
                'payment_allocations' => $allocationCount,
            ],
        ];
    }

    /**
     * Seed discounts.
     *
     * @param  array<string, User>  $users
     * @param  array<string, Payment>  $payments
     * @param  array<string, Invoice>  $invoices
     * @return array{models: array<string, PaymentDiscount>, summary: array<string, int>}
     */
    private function seedDiscounts(array $users, array $payments, array $invoices): array
    {
        $discounts = [];

        foreach ($this->discountDefinitions() as $definition) {
            $payment = $payments[$definition['payment']];
            $invoice = $invoices[$definition['invoice']];
            $approvedBy = $users[$definition['approved_by']];

            $discount = $payment->createDiscount(
                $invoice,
                $definition['amount'],
                $definition['type'],
                $definition['reason'],
                $approvedBy->id
            );

            $invoice->refresh()->updateStatus();
            $discounts[$definition['key']] = $discount;
        }

        return [
            'models' => $discounts,
            'summary' => ['payment_discounts' => count($discounts)],
        ];
    }

    /**
     * Seed share tokens.
     *
     * @param  array<string, User>  $users
     * @param  array<string, Store>  $stores
     * @param  array<int, Customer>  $customers
     * @param  array<string, Invoice>  $invoices
     * @return array{models: array<string, InvoiceShareToken>, summary: array<string, int>}
     */
    private function seedShareTokens(array $users, array $stores, array $customers, array $invoices): array
    {
        $tokens = [];
        $logCount = 0;

        foreach ($this->shareTokenDefinitions() as $key => $definition) {
            $invoiceIds = array_map(
                fn (string $invoiceKey): int => $invoices[$invoiceKey]->id,
                $definition['invoice_keys']
            );

            $token = InvoiceShareToken::create([
                'token' => $definition['token'],
                'invoice_ids' => $invoiceIds,
                'customer_id' => $customers[$definition['customer']]->id,
                'store_id' => $stores[$definition['store']]->id,
                'created_by' => $users[$definition['created_by']]->id,
                'expires_at' => $definition['expires_at'],
                'type' => $definition['type'],
            ]);

            if (($definition['log_access'] ?? false) === true) {
                InvoiceShareTokenLog::create([
                    'token_id' => $token->id,
                    'ip_address' => '127.0.0.1',
                    'user_agent' => 'DEMO local share token access',
                    'accessed_at' => now()->subMinutes(30),
                ]);
                $logCount++;
            }

            $tokens[$key] = $token;
        }

        return [
            'models' => $tokens,
            'summary' => [
                'invoice_share_tokens' => count($tokens),
                'invoice_share_token_logs' => $logCount,
            ],
        ];
    }

    /**
     * Seed attachments and upload intents.
     *
     * @param  array<string, User>  $users
     * @param  array<string, Invoice>  $invoices
     * @param  array<string, Payment>  $payments
     * @return array{models: array<string, Attachment>, summary: array<string, int>}
     */
    private function seedAttachments(array $users, array $invoices, array $payments): array
    {
        $attachments = [];
        $uploadIntents = [];

        foreach ($this->attachmentDefinitions() as $index => $definition) {
            $attachable = $definition['type'] === 'invoice'
                ? $invoices[$definition['key']]
                : $payments[$definition['key']];

            $attachments[$definition['slug']] = Attachment::create([
                'attachable_type' => $definition['type'] === 'invoice' ? Invoice::class : Payment::class,
                'attachable_id' => $attachable->id,
                'original_filename' => $definition['filename'],
                'stored_filename' => $definition['filename'],
                'file_path' => sprintf('demo/attachments/%02d-%s', $index + 1, $definition['filename']),
                'file_size' => $definition['size'],
                'mime_type' => $definition['mime'],
                'uploaded_by' => $users[$definition['uploaded_by']]->id,
            ]);
        }

        foreach ($this->uploadIntentDefinitions() as $index => $definition) {
            $attachable = $definition['type'] === 'invoice'
                ? $invoices[$definition['key']]
                : $payments[$definition['key']];

            $uploadIntents[$definition['slug']] = AttachmentUploadIntent::create([
                'attachable_type' => $definition['type'] === 'invoice' ? Invoice::class : Payment::class,
                'attachable_id' => $attachable->id,
                'file_path' => sprintf('demo/intents/%02d-%s', $index + 1, $definition['filename']),
                'original_filename' => $definition['filename'],
                'file_size' => $definition['size'],
                'mime_type' => $definition['mime'],
                'uploaded_by' => $users[$definition['uploaded_by']]->id,
                'expires_at' => $definition['expires_at'],
                'consumed_at' => $definition['consumed_at'] ?? null,
            ]);
        }

        return [
            'models' => $attachments,
            'summary' => [
                'attachments' => count($attachments),
                'attachment_upload_intents' => count($uploadIntents),
            ],
        ];
    }

    /**
     * Seed runtime config if it is missing.
     *
     * @return array{models: array<int, RuntimeConfig>, summary: array<string, int>}
     */
    private function seedRuntimeConfigIfMissing(): array
    {
        if (RuntimeConfig::where('key', 's3-compat')->exists()) {
            return [
                'models' => [],
                'summary' => ['runtime_configs' => 0],
            ];
        }

        $config = RuntimeConfig::create([
            'key' => 's3-compat',
            'value' => [
                'demo_seeded' => true,
                'access_key' => 'demo-access-key',
                'secret_key' => 'demo-secret-key',
                'region' => 'us-east-1',
                'bucket' => 'demo-local-bucket',
                'endpoint' => 'https://demo.invalid',
                'use_path_style_endpoint' => true,
            ],
        ]);

        return [
            'models' => [$config],
            'summary' => ['runtime_configs' => 1],
        ];
    }

    /**
     * Seed audit logs.
     *
     * @param  array<string, User>  $users
     * @param  array<string, Store>  $stores
     * @param  array<int, Customer>  $customers
     * @param  array<string, Invoice>  $invoices
     * @param  array<string, Payment>  $payments
     * @return array{models: array<int, AuditLog>, summary: array<string, int>}
     */
    private function seedAuditLogs(array $users, array $stores, array $customers, array $invoices, array $payments): array
    {
        $logs = [];
        $actions = [
            AuditLog::ACTION_CREATE,
            AuditLog::ACTION_UPDATE,
            AuditLog::ACTION_VIEW,
            AuditLog::ACTION_ALLOCATE,
            AuditLog::ACTION_DISCOUNT,
            AuditLog::ACTION_UPLOAD,
            AuditLog::ACTION_EXPORT,
            'maintenance_execute',
        ];
        $userModels = array_values($users);
        $storeModels = array_values($stores);
        $customerModels = array_values($customers);
        $invoiceModels = array_values($invoices);
        $paymentModels = array_values($payments);
        $allocationIds = PaymentAllocation::whereIn('payment_id', array_map(fn (Payment $payment): int => $payment->id, $paymentModels))
            ->pluck('id')
            ->all();
        $discountIds = PaymentDiscount::where('reason', 'like', 'DEMO:%')->pluck('id')->all();
        $attachmentIds = Attachment::where('file_path', 'like', 'demo/%')->pluck('id')->all();

        for ($index = 0; $index < 40; $index++) {
            $action = $actions[$index % count($actions)];
            $user = $userModels[$index % count($userModels)];
            $store = $storeModels[$index % count($storeModels)];
            $target = $this->auditTargetForAction(
                $action,
                $index,
                $customerModels,
                $invoiceModels,
                $paymentModels,
                $allocationIds,
                $discountIds,
                $attachmentIds
            );
            $isGlobal = in_array($action, [AuditLog::ACTION_EXPORT, 'maintenance_execute'], true);

            $log = AuditLog::create([
                'user_id' => $user->id,
                'user_name' => $user->name,
                'action' => $action,
                'action_label' => AuditLog::ACTION_LABELS[$action] ?? $action,
                'auditable_type' => $target['type'],
                'auditable_id' => $target['id'],
                'auditable_label' => $target['label'],
                'scope_type' => $isGlobal ? 'global' : 'store',
                'business_store_id' => $isGlobal ? null : $store->id,
                'actor_store_id' => $store->id,
                'old_values' => ['demo' => $index],
                'new_values' => ['demo' => $index + 1],
                'changed_fields' => ['demo'],
                'ip_address' => '127.0.0.1',
                'user_agent' => 'DEMO local seed',
                'request_method' => $action === AuditLog::ACTION_VIEW ? 'GET' : 'POST',
                'request_url' => '/demo/local-seed',
                'description' => sprintf('DEMO: %s audit log %02d', $action, $index + 1),
                'metadata' => ['demo_seeded' => true, 'index' => $index + 1],
                'change_payload' => ['summary' => ['title' => 'DEMO', 'subtitle' => 'local seed']],
                'is_success' => true,
            ]);

            $timestamp = $index < 5
                ? now()->subDays(130 + $index)
                : now()->subDays($index % 30);
            $log->forceFill([
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ])->saveQuietly();

            $logs[] = $log;
        }

        return [
            'models' => $logs,
            'summary' => ['audit_logs' => count($logs)],
        ];
    }

    /**
     * Seed maintenance scenarios.
     *
     * @param  array<string, User>  $users
     * @param  array<int, Customer>  $customers
     * @param  array<string, Store>  $stores
     * @param  array<string, Invoice>  $invoices
     * @param  array<string, Payment>  $payments
     * @return array{models: array<int, array<string, mixed>>, summary: array<string, int>}
     */
    private function seedMaintenanceScenarios(array $users, array $customers, array $stores, array $invoices, array $payments): array
    {
        $created = [];
        $orphanInvoiceId = ((int) Invoice::max('id')) + 10000;
        $orphanPaymentId = ((int) Payment::max('id')) + 10000;

        $this->withRelaxedForeignKeys(function () use (&$created, $orphanInvoiceId, $orphanPaymentId, $users, $invoices): void {
            $created['orphan_invoice_item'] = DB::table('invoice_items')->insertGetId([
                'invoice_id' => $orphanInvoiceId,
                'line_uid' => (string) \Illuminate\Support\Str::uuid(),
                'item_name' => 'DEMO 孤立账单明细',
                'item_description' => 'DEMO: orphan invoice item',
                'quantity' => 1,
                'unit_price' => 99,
                'subtotal' => 99,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $created['orphan_payment_allocation'] = DB::table('payment_allocations')->insertGetId([
                'payment_id' => $orphanPaymentId,
                'invoice_id' => $invoices['maintenanceA_2']->id,
                'amount' => 88,
                'allocated_by' => $users['admin']->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $amountMismatch = $invoices['maintenanceA_2']->refresh();
        DB::table('invoices')
            ->where('id', $amountMismatch->id)
            ->update([
                'amount' => ((float) $amountMismatch->amount) + 123.45,
                'description' => 'DEMO: maintenance amount mismatch invoice',
                'updated_at' => now(),
            ]);
        $created['amount_mismatch_invoice'] = $amountMismatch->id;

        $statusMismatch = $invoices['maintenanceA_3']->refresh();
        DB::table('invoices')
            ->where('id', $statusMismatch->id)
            ->update([
                'status' => 'paid',
                'description' => 'DEMO: maintenance status mismatch invoice',
                'updated_at' => now(),
            ]);
        $created['status_mismatch_invoice'] = $statusMismatch->id;

        return [
            'models' => $created,
            'summary' => ['maintenance_anomalies' => count($created)],
        ];
    }

    /**
     * Synchronize customer store stats.
     *
     * @param  array<int, Customer>  $customers
     * @param  array<string, Store>  $stores
     * @return array{models: array<int, CustomerStoreStat>, summary: array<string, int>}
     */
    private function syncStats(array $customers, array $stores): array
    {
        $stats = [];
        $statsService = app(CustomerStatsService::class);

        foreach ($customers as $customer) {
            foreach ($stores as $store) {
                $stat = $statsService->syncCustomerStoreStats($customer->id, $store->id);

                if ($stat !== null) {
                    $stats[] = $stat;
                }
            }
        }

        return [
            'models' => $stats,
            'summary' => ['customer_store_stats' => count($stats)],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function shareTokenDefinitions(): array
    {
        return [
            'fixed_single' => [
                'token' => 'demo-fixed-single-token',
                'invoice_keys' => ['shareA_1'],
                'customer' => 'shareA',
                'store' => 'A',
                'created_by' => 'ownerA',
                'expires_at' => now()->addMonths(3),
                'type' => InvoiceShareToken::TYPE_FIXED,
            ],
            'fixed_multiple' => [
                'token' => 'demo-fixed-multiple-token',
                'invoice_keys' => ['shareA_1', 'shareA_2', 'shareA_3'],
                'customer' => 'shareA',
                'store' => 'A',
                'created_by' => 'ownerA',
                'expires_at' => now()->addMonths(3),
                'type' => InvoiceShareToken::TYPE_FIXED,
            ],
            'dynamic_store_a' => [
                'token' => 'demo-dynamic-store-a-token',
                'invoice_keys' => ['debtA_1', 'debtA_2'],
                'customer' => 'debtA',
                'store' => 'A',
                'created_by' => 'ownerA',
                'expires_at' => now()->addMonths(2),
                'type' => InvoiceShareToken::TYPE_DYNAMIC,
            ],
            'dynamic_store_b' => [
                'token' => 'demo-dynamic-store-b-token',
                'invoice_keys' => ['debtB_1', 'debtB_2'],
                'customer' => 'debtB',
                'store' => 'B',
                'created_by' => 'ownerB',
                'expires_at' => now()->addMonths(2),
                'type' => InvoiceShareToken::TYPE_DYNAMIC,
            ],
            'expired_fixed' => [
                'token' => 'demo-expired-fixed-token',
                'invoice_keys' => ['overdueA_3'],
                'customer' => 'overdueA',
                'store' => 'A',
                'created_by' => 'ownerA',
                'expires_at' => now()->subDay(),
                'type' => InvoiceShareToken::TYPE_FIXED,
            ],
            'access_log' => [
                'token' => 'demo-access-log-token',
                'invoice_keys' => ['shareA_3'],
                'customer' => 'shareA',
                'store' => 'A',
                'created_by' => 'ownerA',
                'expires_at' => now()->addMonth(),
                'type' => InvoiceShareToken::TYPE_FIXED,
                'log_access' => true,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function attachmentDefinitions(): array
    {
        return [
            ['slug' => 'invoice_image', 'type' => 'invoice', 'key' => 'attachmentA_1', 'filename' => 'DEMO-invoice-image.jpg', 'mime' => 'image/jpeg', 'size' => 128000, 'uploaded_by' => 'staffA'],
            ['slug' => 'invoice_pdf', 'type' => 'invoice', 'key' => 'attachmentA_2', 'filename' => 'DEMO-invoice.pdf', 'mime' => 'application/pdf', 'size' => 256000, 'uploaded_by' => 'ownerA'],
            ['slug' => 'invoice_text', 'type' => 'invoice', 'key' => 'shareA_1', 'filename' => 'DEMO-note.txt', 'mime' => 'text/plain', 'size' => 4096, 'uploaded_by' => 'staffA'],
            ['slug' => 'invoice_docx', 'type' => 'invoice', 'key' => 'discountA_1', 'filename' => 'DEMO-contract.docx', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'size' => 89000, 'uploaded_by' => 'ownerA'],
            ['slug' => 'payment_image', 'type' => 'payment', 'key' => 'pay006', 'filename' => 'DEMO-payment-image.png', 'mime' => 'image/png', 'size' => 98000, 'uploaded_by' => 'staffA'],
            ['slug' => 'payment_pdf', 'type' => 'payment', 'key' => 'pay002', 'filename' => 'DEMO-payment.pdf', 'mime' => 'application/pdf', 'size' => 145000, 'uploaded_by' => 'ownerA'],
            ['slug' => 'payment_text', 'type' => 'payment', 'key' => 'pay024', 'filename' => 'DEMO-payment-note.txt', 'mime' => 'text/plain', 'size' => 2048, 'uploaded_by' => 'staffB'],
            ['slug' => 'payment_doc', 'type' => 'payment', 'key' => 'pay025', 'filename' => 'DEMO-payment-doc.doc', 'mime' => 'application/msword', 'size' => 76000, 'uploaded_by' => 'admin'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function uploadIntentDefinitions(): array
    {
        return [
            ['slug' => 'intent_invoice_image', 'type' => 'invoice', 'key' => 'attachmentA_3', 'filename' => 'DEMO-intent-image.jpg', 'mime' => 'image/jpeg', 'size' => 110000, 'uploaded_by' => 'staffA', 'expires_at' => now()->addHour()],
            ['slug' => 'intent_invoice_pdf', 'type' => 'invoice', 'key' => 'shareA_2', 'filename' => 'DEMO-intent.pdf', 'mime' => 'application/pdf', 'size' => 210000, 'uploaded_by' => 'ownerA', 'expires_at' => now()->addHours(2)],
            ['slug' => 'intent_payment_text', 'type' => 'payment', 'key' => 'pay007', 'filename' => 'DEMO-intent-note.txt', 'mime' => 'text/plain', 'size' => 3072, 'uploaded_by' => 'staffA', 'expires_at' => now()->addHours(3), 'consumed_at' => now()->subMinutes(10)],
            ['slug' => 'intent_payment_docx', 'type' => 'payment', 'key' => 'pay026', 'filename' => 'DEMO-intent.docx', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'size' => 64000, 'uploaded_by' => 'staffB', 'expires_at' => now()->addHours(4)],
        ];
    }

    /**
     * @param  array<int, Customer>  $customers
     * @param  array<int, Invoice>  $invoices
     * @param  array<int, Payment>  $payments
     * @param  array<int, int>  $allocationIds
     * @param  array<int, int>  $discountIds
     * @param  array<int, int>  $attachmentIds
     * @return array{type: string|null, id: int|null, label: string|null}
     */
    private function auditTargetForAction(
        string $action,
        int $index,
        array $customers,
        array $invoices,
        array $payments,
        array $allocationIds,
        array $discountIds,
        array $attachmentIds
    ): array {
        return match ($action) {
            AuditLog::ACTION_CREATE => [
                'type' => Invoice::class,
                'id' => $invoices[$index % count($invoices)]->id,
                'label' => $invoices[$index % count($invoices)]->invoice_number,
            ],
            AuditLog::ACTION_UPDATE => [
                'type' => Customer::class,
                'id' => $customers[$index % count($customers)]->id,
                'label' => $customers[$index % count($customers)]->name,
            ],
            AuditLog::ACTION_VIEW => [
                'type' => Payment::class,
                'id' => $payments[$index % count($payments)]->id,
                'label' => $payments[$index % count($payments)]->payment_number,
            ],
            AuditLog::ACTION_ALLOCATE => [
                'type' => PaymentAllocation::class,
                'id' => $this->cyclicId($allocationIds, $index),
                'label' => 'DEMO payment allocation',
            ],
            AuditLog::ACTION_DISCOUNT => [
                'type' => PaymentDiscount::class,
                'id' => $this->cyclicId($discountIds, $index),
                'label' => 'DEMO payment discount',
            ],
            AuditLog::ACTION_UPLOAD => [
                'type' => Attachment::class,
                'id' => $this->cyclicId($attachmentIds, $index),
                'label' => 'DEMO attachment',
            ],
            AuditLog::ACTION_EXPORT => [
                'type' => Invoice::class,
                'id' => $invoices[$index % count($invoices)]->id,
                'label' => 'DEMO export',
            ],
            default => [
                'type' => 'maintenance',
                'id' => null,
                'label' => 'DEMO maintenance execution',
            ],
        };
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function cyclicId(array $ids, int $index): ?int
    {
        return $ids === []
            ? null
            : $ids[$index % count($ids)];
    }

    private function creatorKeyForStore(string $storeKey): string
    {
        return match ($storeKey) {
            'A' => 'ownerA',
            'B' => 'ownerB',
            default => 'admin',
        };
    }

    private function receiverKeyForStore(string $storeKey): string
    {
        return match ($storeKey) {
            'A' => 'staffA',
            'B' => 'staffB',
            default => 'admin',
        };
    }

    private function demoInvoiceDueDate(int $slot, int $sequence): \Illuminate\Support\Carbon
    {
        if ($slot === 2 && $sequence % 5 === 0) {
            return now();
        }

        return match ($slot) {
            1 => now()->addDays(14 + ($sequence % 9)),
            2 => now()->endOfMonth()->subDays($sequence % 4),
            default => now()->subDays(10 + ($sequence % 20)),
        };
    }

    private function demoInvoiceCreatedAt(int $slot, int $sequence): \Illuminate\Support\Carbon
    {
        return match ($slot) {
            1 => now()->subDays($sequence % 7),
            2 => now()->subDays(15 + ($sequence % 7)),
            default => now()->subMonths(4)->subDays($sequence % 10),
        };
    }

    /**
     * @param  array<int, array{invoice: string, amount: int|float|string}>  $allocations
     * @param  array<string, Invoice>  $invoices
     */
    private function allocationSum(array $allocations, array $invoices): float
    {
        return array_reduce(
            $allocations,
            fn (float $sum, array $allocation): float => $sum + $this->resolveAllocationAmount(
                $invoices[$allocation['invoice']],
                $allocation['amount']
            ),
            0.0
        );
    }

    private function resolveAllocationAmount(Invoice $invoice, int|float|string $amount): float
    {
        return $amount === 'full'
            ? (float) $invoice->amount
            : (float) $amount;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function paymentDefinitions(): array
    {
        return [
            ['key' => 'pay001', 'customer' => 'paidA', 'method' => 'cash', 'allocations' => [
                ['invoice' => 'paidA_1', 'amount' => 'full'],
                ['invoice' => 'paidA_2', 'amount' => 'full'],
                ['invoice' => 'paidA_3', 'amount' => 'full'],
            ]],
            ['key' => 'pay002', 'customer' => 'debtA', 'method' => 'bank_transfer', 'extra' => 50, 'allocations' => [
                ['invoice' => 'debtA_1', 'amount' => 'full'],
                ['invoice' => 'debtA_2', 'amount' => 100],
            ]],
            ['key' => 'pay003', 'customer' => 'overdueA', 'method' => 'wechat', 'extra' => 100, 'allocations' => [
                ['invoice' => 'overdueA_1', 'amount' => 220],
                ['invoice' => 'overdueA_2', 'amount' => 100],
            ]],
            ['key' => 'pay004', 'customer' => 'discountA', 'method' => 'alipay', 'allocations' => [
                ['invoice' => 'discountA_1', 'amount' => 'full'],
                ['invoice' => 'discountA_2', 'amount' => 300],
            ]],
            ['key' => 'pay005', 'customer' => 'writeoffA', 'method' => 'other', 'extra' => 25, 'allocations' => [
                ['invoice' => 'writeoffA_1', 'amount' => 100],
                ['invoice' => 'writeoffA_2', 'amount' => 150],
            ]],
            ['key' => 'pay006', 'customer' => 'attachmentA', 'method' => 'cash', 'allocations' => [
                ['invoice' => 'attachmentA_1', 'amount' => 'full'],
                ['invoice' => 'attachmentA_2', 'amount' => 200],
            ]],
            ['key' => 'pay007', 'customer' => 'shareA', 'method' => 'bank_transfer', 'extra' => 75, 'allocations' => [
                ['invoice' => 'shareA_1', 'amount' => 'full'],
                ['invoice' => 'shareA_2', 'amount' => 250],
            ]],
            ['key' => 'pay008', 'customer' => 'sameNameA', 'method' => 'wechat', 'extra' => 200, 'allocations' => [
                ['invoice' => 'sameNameA_1', 'amount' => 200],
            ]],
            ['key' => 'pay009', 'customer' => 'sameNameB', 'method' => 'alipay', 'allocations' => [
                ['invoice' => 'sameNameB_1', 'amount' => 'full'],
            ]],
            ['key' => 'pay010', 'customer' => 'debtB', 'method' => 'other', 'extra' => 80, 'allocations' => [
                ['invoice' => 'debtB_1', 'amount' => 300],
            ]],
            ['key' => 'pay011', 'customer' => 'paidB', 'method' => 'cash', 'allocations' => [
                ['invoice' => 'paidB_1', 'amount' => 'full'],
            ]],
            ['key' => 'pay012', 'customer' => 'filterB', 'method' => 'bank_transfer', 'extra' => 120, 'allocations' => [
                ['invoice' => 'filterB_1', 'amount' => 400],
            ]],
            ['key' => 'pay013', 'customer' => 'edgeC', 'method' => 'wechat', 'allocations' => [
                ['invoice' => 'edgeC_1', 'amount' => 'full'],
            ]],
            ['key' => 'pay014', 'customer' => 'emptyC', 'method' => 'alipay', 'extra' => 100, 'allocations' => [
                ['invoice' => 'emptyC_1', 'amount' => 500],
            ]],
            ['key' => 'pay015', 'customer' => 'maintenanceA', 'method' => 'other', 'extra' => 100, 'allocations' => [
                ['invoice' => 'maintenanceA_1', 'amount' => 600],
            ]],
            ['key' => 'pay016', 'customer' => 'discountA', 'method' => 'cash', 'extra' => 50, 'allocations' => [
                ['invoice' => 'discountA_3', 'amount' => 100],
            ]],
            ['key' => 'pay017', 'customer' => 'writeoffA', 'method' => 'bank_transfer', 'extra' => 80, 'allocations' => [
                ['invoice' => 'writeoffA_3', 'amount' => 120],
            ]],
            ['key' => 'pay018', 'customer' => 'attachmentA', 'method' => 'wechat', 'allocations' => [
                ['invoice' => 'attachmentA_3', 'amount' => 'full'],
            ]],
            ['key' => 'pay019', 'customer' => 'shareA', 'method' => 'alipay', 'extra' => 100, 'allocations' => [
                ['invoice' => 'shareA_3', 'amount' => 250],
            ]],
            ['key' => 'pay020', 'customer' => 'sameNameA', 'method' => 'other', 'allocations' => [
                ['invoice' => 'sameNameA_2', 'amount' => 'full'],
            ]],
            ['key' => 'pay021', 'customer' => 'sameNameB', 'method' => 'cash', 'extra' => 100, 'allocations' => [
                ['invoice' => 'sameNameB_2', 'amount' => 300],
            ]],
            ['key' => 'pay022', 'customer' => 'debtB', 'method' => 'bank_transfer', 'allocations' => [
                ['invoice' => 'debtB_2', 'amount' => 'full'],
            ]],
            ['key' => 'pay023', 'customer' => 'paidB', 'method' => 'wechat', 'allocations' => [
                ['invoice' => 'paidB_3', 'amount' => 'full'],
            ]],
            ['key' => 'pay024', 'customer' => 'filterB', 'method' => 'alipay', 'extra' => 100, 'allocations' => [
                ['invoice' => 'filterB_2', 'amount' => 500],
            ]],
            ['key' => 'pay025', 'customer' => 'debtA', 'method' => 'other', 'amount' => 180, 'remarks' => 'DEMO: unallocated payment store A'],
            ['key' => 'pay026', 'customer' => 'filterB', 'method' => 'cash', 'amount' => 260, 'remarks' => 'DEMO: unallocated payment store B'],
            ['key' => 'pay027', 'customer' => 'edgeC', 'method' => 'bank_transfer', 'amount' => 370, 'remarks' => 'DEMO: unallocated payment store C'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function discountDefinitions(): array
    {
        return [
            ['key' => 'disc001', 'payment' => 'pay002', 'invoice' => 'debtA_2', 'amount' => 240, 'type' => PaymentDiscount::TYPE_DISCOUNT, 'approved_by' => 'ownerA', 'reason' => 'DEMO: 清欠抹零折扣'],
            ['key' => 'disc002', 'payment' => 'pay003', 'invoice' => 'overdueA_1', 'amount' => 100, 'type' => PaymentDiscount::TYPE_PROMOTION, 'approved_by' => 'ownerA', 'reason' => 'DEMO: 逾期客户促销减免'],
            ['key' => 'disc003', 'payment' => 'pay003', 'invoice' => 'overdueA_2', 'amount' => 50, 'type' => PaymentDiscount::TYPE_WRITE_OFF, 'approved_by' => 'admin', 'reason' => 'DEMO: 逾期坏账核销'],
            ['key' => 'disc004', 'payment' => 'pay004', 'invoice' => 'discountA_2', 'amount' => 220, 'type' => PaymentDiscount::TYPE_DISCOUNT, 'approved_by' => 'ownerA', 'reason' => 'DEMO: 折扣客户尾款减免'],
            ['key' => 'disc005', 'payment' => 'pay004', 'invoice' => 'discountA_3', 'amount' => 100, 'type' => PaymentDiscount::TYPE_PROMOTION, 'approved_by' => 'ownerA', 'reason' => 'DEMO: 活动促销优惠'],
            ['key' => 'disc006', 'payment' => 'pay005', 'invoice' => 'writeoffA_1', 'amount' => 460, 'type' => PaymentDiscount::TYPE_WRITE_OFF, 'approved_by' => 'admin', 'reason' => 'DEMO: 大额坏账核销'],
            ['key' => 'disc007', 'payment' => 'pay005', 'invoice' => 'writeoffA_2', 'amount' => 70, 'type' => PaymentDiscount::TYPE_WRITE_OFF, 'approved_by' => 'admin', 'reason' => 'DEMO: 小额坏账核销'],
            ['key' => 'disc008', 'payment' => 'pay006', 'invoice' => 'attachmentA_2', 'amount' => 440, 'type' => PaymentDiscount::TYPE_DISCOUNT, 'approved_by' => 'ownerA', 'reason' => 'DEMO: 附件客户折扣'],
            ['key' => 'disc009', 'payment' => 'pay007', 'invoice' => 'shareA_2', 'amount' => 100, 'type' => PaymentDiscount::TYPE_PROMOTION, 'approved_by' => 'ownerA', 'reason' => 'DEMO: 分享客户促销'],
            ['key' => 'disc010', 'payment' => 'pay008', 'invoice' => 'sameNameA_1', 'amount' => 100, 'type' => PaymentDiscount::TYPE_DISCOUNT, 'approved_by' => 'ownerA', 'reason' => 'DEMO: 同名客户折扣'],
            ['key' => 'disc011', 'payment' => 'pay010', 'invoice' => 'debtB_1', 'amount' => 300, 'type' => PaymentDiscount::TYPE_PROMOTION, 'approved_by' => 'ownerB', 'reason' => 'DEMO: 门店B促销优惠'],
            ['key' => 'disc012', 'payment' => 'pay024', 'invoice' => 'filterB_2', 'amount' => 500, 'type' => PaymentDiscount::TYPE_WRITE_OFF, 'approved_by' => 'ownerB', 'reason' => 'DEMO: 门店B坏账核销'],
        ];
    }

    /**
     * Return all demo store definitions.
     *
     * @return array<string, array<string, mixed>>
     */
    private function storeDefinitions(): array
    {
        return [
            'A' => [
                'name' => 'DEMO-门店A 主流程',
                'code' => 'DEMO-A',
                'address' => 'DEMO: 本地测试地址 A',
                'phone' => '13800000001',
                'description' => 'DEMO: 主流程门店',
                'is_active' => true,
                'wechat_pay_code_data' => 'DEMO-WECHAT-A',
                'alipay_code_data' => 'DEMO-ALIPAY-A',
            ],
            'B' => [
                'name' => 'DEMO-门店B 权限隔离',
                'code' => 'DEMO-B',
                'address' => 'DEMO: 本地测试地址 B',
                'phone' => '13800000002',
                'description' => 'DEMO: 权限隔离门店',
                'is_active' => true,
                'wechat_pay_code_data' => 'DEMO-WECHAT-B',
                'alipay_code_data' => 'DEMO-ALIPAY-B',
            ],
            'C' => [
                'name' => 'DEMO-门店C 边界场景',
                'code' => 'DEMO-C',
                'address' => 'DEMO: 本地测试地址 C',
                'phone' => '13800000003',
                'description' => 'DEMO: 边界门店',
                'is_active' => true,
                'wechat_pay_code_data' => 'DEMO-WECHAT-C',
                'alipay_code_data' => 'DEMO-ALIPAY-C',
            ],
        ];
    }

    /**
     * Return all demo user definitions.
     *
     * @return array<string, array<string, mixed>>
     */
    private function userDefinitions(): array
    {
        return [
            'admin' => [
                'name' => 'DEMO 系统管理员',
                'username' => 'demo_admin',
                'email' => 'demo.admin@example.com',
                'role_slugs' => ['admin'],
                'stores' => ['A', 'B', 'C'],
            ],
            'ownerA' => [
                'name' => 'DEMO 门店A店长',
                'username' => 'demo_owner_a',
                'email' => 'demo.owner.a@example.com',
                'role_slugs' => ['store_owner'],
                'stores' => ['A'],
            ],
            'ownerB' => [
                'name' => 'DEMO 门店B店长',
                'username' => 'demo_owner_b',
                'email' => 'demo.owner.b@example.com',
                'role_slugs' => ['store_owner'],
                'stores' => ['B'],
            ],
            'staffA' => [
                'name' => 'DEMO 门店A店员',
                'username' => 'demo_staff_a',
                'email' => 'demo.staff.a@example.com',
                'role_slugs' => ['store_staff'],
                'stores' => ['A'],
            ],
            'staffB' => [
                'name' => 'DEMO 门店B店员',
                'username' => 'demo_staff_b',
                'email' => 'demo.staff.b@example.com',
                'role_slugs' => ['store_staff'],
                'stores' => ['B'],
            ],
            'multi' => [
                'name' => 'DEMO 跨店用户',
                'username' => 'demo_multi',
                'email' => 'demo.multi@example.com',
                'role_slugs' => ['store_owner', 'store_staff'],
                'stores' => ['A', 'B'],
            ],
        ];
    }

    /**
     * Return all demo customer definitions.
     *
     * @return array<int, array<string, mixed>>
     */
    private function customerDefinitions(): array
    {
        return [
            'debtA' => ['store' => 'A', 'name' => 'DEMO 张三 欠款客户', 'phone' => '13900000001', 'email' => 'demo.customer.01@example.com', 'address' => 'DEMO: 本地测试地址 debtA', 'remarks' => 'DEMO: debt customer store A'],
            'paidA' => ['store' => 'A', 'name' => 'DEMO 李四 无欠款客户', 'phone' => '13900000002', 'email' => 'demo.customer.02@example.com', 'address' => 'DEMO: 本地测试地址 paidA', 'remarks' => 'DEMO: paid customer store A'],
            'overdueA' => ['store' => 'A', 'name' => 'DEMO 王五 逾期客户', 'phone' => '13900000003', 'email' => 'demo.customer.03@example.com', 'address' => 'DEMO: 本地测试地址 overdueA', 'remarks' => 'DEMO: overdue customer store A'],
            'discountA' => ['store' => 'A', 'name' => 'DEMO 赵六 折扣客户', 'phone' => '13900000004', 'email' => 'demo.customer.04@example.com', 'address' => 'DEMO: 本地测试地址 discountA', 'remarks' => 'DEMO: discount customer store A'],
            'writeoffA' => ['store' => 'A', 'name' => 'DEMO 钱七 核销客户', 'phone' => '13900000005', 'email' => 'demo.customer.05@example.com', 'address' => 'DEMO: 本地测试地址 writeoffA', 'remarks' => 'DEMO: write off customer store A'],
            'attachmentA' => ['store' => 'A', 'name' => 'DEMO 孙八 附件客户', 'phone' => '13900000006', 'email' => 'demo.customer.06@example.com', 'address' => 'DEMO: 本地测试地址 attachmentA', 'remarks' => 'DEMO: attachment customer store A'],
            'shareA' => ['store' => 'A', 'name' => 'DEMO 周九 分享客户', 'phone' => '13900000007', 'email' => 'demo.customer.07@example.com', 'address' => 'DEMO: 本地测试地址 shareA', 'remarks' => 'DEMO: share token customer store A'],
            'sameNameA' => ['store' => 'A', 'name' => 'DEMO 同名客户', 'phone' => '13900000008', 'email' => 'demo.customer.08@example.com', 'address' => 'DEMO: 本地测试地址 sameNameA', 'remarks' => 'DEMO: same name customer store A'],
            'sameNameB' => ['store' => 'B', 'name' => 'DEMO 同名客户', 'phone' => '13900000009', 'email' => 'demo.customer.09@example.com', 'address' => 'DEMO: 本地测试地址 sameNameB', 'remarks' => 'DEMO: same name customer store B'],
            'debtB' => ['store' => 'B', 'name' => 'DEMO 吴十 门店B欠款', 'phone' => '13900000010', 'email' => 'demo.customer.10@example.com', 'address' => 'DEMO: 本地测试地址 debtB', 'remarks' => 'DEMO: debt customer store B'],
            'paidB' => ['store' => 'B', 'name' => 'DEMO 郑一 门店B无欠款', 'phone' => '13900000011', 'email' => 'demo.customer.11@example.com', 'address' => 'DEMO: 本地测试地址 paidB', 'remarks' => 'DEMO: paid customer store B'],
            'filterB' => ['store' => 'B', 'name' => 'DEMO 筛选客户B', 'phone' => '13900000012', 'email' => 'demo.customer.12@example.com', 'address' => 'DEMO: 本地测试地址 filterB', 'remarks' => 'DEMO: filter customer store B'],
            'edgeC' => ['store' => 'C', 'name' => 'DEMO 边界客户C', 'phone' => '13900000013', 'email' => 'demo.customer.13@example.com', 'address' => 'DEMO: 本地测试地址 edgeC', 'remarks' => 'DEMO: edge customer store C'],
            'emptyC' => ['store' => 'C', 'name' => 'DEMO 空态客户C', 'phone' => '13900000014', 'email' => 'demo.customer.14@example.com', 'address' => 'DEMO: 本地测试地址 emptyC', 'remarks' => 'DEMO: empty state customer store C'],
            'maintenanceA' => ['store' => 'C', 'name' => 'DEMO 维护场景客户', 'phone' => '13900000015', 'email' => 'demo.customer.15@example.com', 'address' => 'DEMO: 本地测试地址 maintenanceA', 'remarks' => 'DEMO: maintenance customer store A'],
        ];
    }

    /**
     * Run a block without demo auditing.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    private function withoutDemoAuditing(callable $callback): mixed
    {
        return User::withoutAuditingDo(fn () => Store::withoutAuditingDo(fn () => Customer::withoutAuditingDo(fn () => Invoice::withoutAuditingDo(fn () => Payment::withoutAuditingDo(fn () => Attachment::withoutAuditingDo($callback))))));
    }

    /**
     * Temporarily relax foreign key constraints.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    private function withRelaxedForeignKeys(callable $callback): mixed
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');

            try {
                return $callback();
            } finally {
                DB::statement('PRAGMA defer_foreign_keys = OFF');
            }
        }

        Schema::disableForeignKeyConstraints();

        try {
            return $callback();
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }
}
