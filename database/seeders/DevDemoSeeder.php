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
        return [
            'models' => [],
            'summary' => [],
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
        return [
            'models' => [],
            'summary' => [],
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
        return [
            'models' => [],
            'summary' => [],
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
        return [
            'models' => [],
            'summary' => [],
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
        return [
            'models' => [],
            'summary' => [],
        ];
    }

    /**
     * Seed runtime config if it is missing.
     *
     * @return array{models: array<int, RuntimeConfig>, summary: array<string, int>}
     */
    private function seedRuntimeConfigIfMissing(): array
    {
        return [
            'models' => [],
            'summary' => [],
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
        return [
            'models' => [],
            'summary' => [],
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
        return [
            'models' => [],
            'summary' => [],
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
        return [
            'models' => [],
            'summary' => [],
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
