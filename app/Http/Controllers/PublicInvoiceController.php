<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceShareToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * @group 公开账单接口
 *
 * 用于小程序等外部访问的公开接口（无需登录）
 */
class PublicInvoiceController extends ApiController
{
    /**
     * 生成分享链接
     *
     * 生成可分享的账单链接，支持单账单或多账单（同一客户）
     * 需要登录态
     *
     * @bodyParam invoice_ids array required 账单ID数组 Example: [1, 2, 3]
     * @bodyParam expires_hours integer 链接有效期（小时），默认2160（3个月） Example: 2160
     *
     * @response 200 scenario="生成成功" {
     *   "success": true,
     *   "data": {
     *     "share_token": "abc123xyz",
     *     "mini_program_path": "/pages/bill/index?token=abc123xyz",
     *     "expires_at": "2026-04-30T19:48:17+08:00"
     *   }
     * }
     */
    public function createShareLink(Request $request)
    {
        $type = $request->input('type', InvoiceShareToken::TYPE_FIXED);

        $rules = [
            'type' => 'nullable|in:fixed,dynamic',
            'expires_hours' => 'nullable|integer|min:1|max:8760', // 最长1年
        ];

        if ($type === InvoiceShareToken::TYPE_DYNAMIC) {
            $rules['customer_id'] = 'required|integer|exists:customers,id';
            $rules['store_id'] = 'required|integer|exists:stores,id';
            $rules['invoice_ids'] = 'nullable|array';
        } else {
            $rules['invoice_ids'] = 'required|array|min:1';
            $rules['invoice_ids.*'] = 'required|integer|exists:invoices,id';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        $expiresHours = $request->input('expires_hours', 2160); // 默认3个月

        $customerId = null;
        $storeId = null;
        $invoiceIds = [];

        if ($type === InvoiceShareToken::TYPE_DYNAMIC) {
            $customerId = $request->input('customer_id');
            $storeId = $request->input('store_id');
            // 动态模式下，invoice_ids 为空
            $invoiceIds = [];

            // 验证用户有权限分享该门店
            if (! $this->isAdmin() && ! $this->belongsToStore($storeId)) {
                return $this->errorResponse('您没有权限分享该门店的账单', 403);
            }

            // 验证客户属于该门店
            $customer = \App\Models\Customer::find($customerId);
            if (! $customer || $customer->store_id != $storeId) {
                return $this->errorResponse('客户不属于该门店', 422);
            }
        } else {
            $invoiceIds = $request->input('invoice_ids');

            // 获取账单并验证权限
            $invoices = Invoice::query() // 优化：只查必要的字段
                ->whereIn('id', $invoiceIds)
                ->get(['id', 'customer_id', 'store_id']);

            if ($invoices->count() !== count($invoiceIds)) {
                return $this->errorResponse('部分账单不存在', 404);
            }

            // 验证所有账单属于同一客户和同一门店
            $customerIds = $invoices->pluck('customer_id')->unique();
            $storeIds = $invoices->pluck('store_id')->unique();

            if ($customerIds->count() > 1) {
                return $this->errorResponse('所选账单必须属于同一客户', 422);
            }

            if ($storeIds->count() > 1) {
                return $this->errorResponse('所选账单必须属于同一门店', 422);
            }

            $storeId = $storeIds->first();
            $customerId = $customerIds->first();

            // 验证用户有权限访问这些账单的门店
            if (! $this->isAdmin() && ! $this->belongsToStore($storeId)) {
                return $this->errorResponse('您没有权限分享该门店的账单', 403);
            }
        }

        $user = Auth::user();

        // 优化：如果是 dynamic 类型，尝试复用现有的有效 Token
        if ($type === InvoiceShareToken::TYPE_DYNAMIC) {
            $existingToken = InvoiceShareToken::where('type', InvoiceShareToken::TYPE_DYNAMIC)
                ->where('customer_id', $customerId)
                ->where('store_id', $storeId)
                ->where('expires_at', '>', now())
                ->orderBy('created_at', 'desc') // 取最新的
                ->first();

            if ($existingToken) {
                // 复用现有 Token
                $token = $existingToken;

                // 检查是否需要刷新小程序码（如果之前没生成成功）
                // 但通常如果 Token 存在，之前的流程应该已经尝试过生成
                // 直接跳到返回部分
            } else {
                // 创建新 Token
                $token = InvoiceShareToken::create([
                    'token' => InvoiceShareToken::generateToken(),
                    'type' => $type,
                    'invoice_ids' => $invoiceIds, // 动态模式下为空数组
                    'customer_id' => $customerId,
                    'store_id' => $storeId,
                    'created_by' => $user->id,
                    'expires_at' => now()->addHours($expiresHours),
                ]);
            }
        } else {
            // Fixed 模式：每次通过 invoice_ids 生成新的快照
            $token = InvoiceShareToken::create([
                'token' => InvoiceShareToken::generateToken(),
                'type' => $type,
                'invoice_ids' => $invoiceIds,
                'customer_id' => $customerId,
                'store_id' => $storeId,
                'created_by' => $user->id,
                'expires_at' => now()->addHours($expiresHours),
            ]);
        }

        // 生成微信小程序码（不阻塞主流程）
        $qrCodeBase64 = null;
        try {
            /** @var \App\Services\WechatService $wechatService */
            $wechatService = app(\App\Services\WechatService::class);
            if ($wechatService->isConfigured()) {
                // scene 最大32字符，token 正好32字符
                // 使用 token 作为 scene 参数
                $imageContent = $wechatService->getUnlimitedQRCode(
                    $token->token,
                    'pages/bill/index' // 确保此页面在小程序 app.json 中定义
                );

                if ($imageContent) {
                    $qrCodeBase64 = 'data:image/jpeg;base64,'.base64_encode($imageContent);
                }
            }
        } catch (\Exception $e) {
            \Log::warning('小程序码生成异常', ['error' => $e->getMessage()]);
        }

        return $this->successResponse([
            'share_token' => $token->token,
            'mini_program_path' => "/pages/bill/index?token={$token->token}",
            'qr_code_base64' => $qrCodeBase64,
            'expires_at' => $token->expires_at->toIso8601String(),
        ]);
    }

    /**
     * 公开获取账单数据
     *
     * 通过分享令牌获取账单详情，无需登录
     *
     * @urlParam token string required 分享令牌 Example: abc123xyz
     *
     * @response 200 scenario="单账单模式" {
     *   "success": true,
     *   "data": {
     *     "mode": "single",
     *     "store": {"id": 1, "name": "贵献花卉", "phone": "13800138000"},
     *     "customer": {"id": 1, "name": "张三", "phone": "138****8001"},
     *     "invoice": {...},
     *     "expires_at": "2026-04-30T19:48:17+08:00"
     *   }
     * }
     * @response 200 scenario="多账单模式" {
     *   "success": true,
     *   "data": {
     *     "mode": "multiple",
     *     "store": {"id": 1, "name": "贵献花卉", "phone": "13800138000"},
     *     "customer": {"id": 1, "name": "张三", "phone": "138****8001"},
     *     "summary": {...},
     *     "invoices": [...],
     *     "expires_at": "2026-04-30T19:48:17+08:00"
     *   }
     * }
     * @response 404 scenario="令牌无效" {
     *   "success": false,
     *   "message": "分享链接不存在或已失效"
     * }
     */
    public function show(Request $request, string $token)
    {
        // 查找令牌
        $shareToken = InvoiceShareToken::with(['store:id,name,phone', 'customer:id,name,phone'])
            ->where('token', $token)
            ->first();

        if (! $shareToken) {
            return $this->errorResponse('分享链接不存在', 404);
        }

        if (! $shareToken->isValid()) {
            return $this->errorResponse('分享链接已过期', 410); // 410 Gone
        }

        // 记录访问日志（仅首页记录，避免翻页重复记录）
        $page = $request->input('page');
        if (! $page || $page == 1) {
            $shareToken->logAccess(
                $request->ip(),
                $request->userAgent()
            );
        }

        // 根据类型获取账单 ID 列表
        $invoiceIds = [];
        if ($shareToken->type === InvoiceShareToken::TYPE_DYNAMIC) {
            // 动态模式：实时查询未付和部分支付的账单
            $invoiceIds = Invoice::where('customer_id', $shareToken->customer_id)
                ->where('store_id', $shareToken->store_id)
                ->whereIn('status', ['unpaid', 'partially_paid'])
                ->orderBy('created_at', 'desc')
                ->pluck('id')
                ->toArray();
        } else {
            // 固定模式：使用存储的 ID 列表
            $invoiceIds = $shareToken->invoice_ids ?? [];
        }

        $totalCount = count($invoiceIds);
        $store = $shareToken->store;
        $customer = $shareToken->customer;
        $maskedPhone = $this->maskPhone($customer->phone);

        // 如果没有账单（例如动态模式下所有账单已付）
        if ($totalCount === 0) {
            return $this->successResponse([
                'mode' => 'multiple',
                'store' => [
                    'id' => $store->id,
                    'name' => $store->name,
                    'phone' => $store->phone,
                ],
                'customer' => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'phone' => $maskedPhone,
                ],
                'summary' => [
                    'total_count' => 0,
                    'total_amount' => '0.00',
                    'total_paid' => '0.00',
                    'total_discount' => '0.00',
                    'total_remaining' => '0.00',
                ],
                'invoices' => [],
                'expires_at' => $shareToken->expires_at->toIso8601String(),
            ]);
        }

        $isSingle = $totalCount === 1;

        if ($isSingle) {
            // 单账单模式（不需要分页）
            $invoice = Invoice::with(['items', 'store:id,name,phone', 'customer:id,name,phone', 'discounts', 'attachments', 'createdBy:id,name'])
                ->find($invoiceIds[0]);

            // 再次检查账单是否存在（防止物理删除）
            if (! $invoice) {
                // 如果单账单模式下账单找不到了，作为空列表返回以免前端报错
                return $this->successResponse([
                    'mode' => 'multiple', // 降级为 multiple 模式返回空
                    'store' => [
                        'id' => $store->id,
                        'name' => $store->name,
                        'phone' => $store->phone,
                    ],
                    'customer' => [
                        'id' => $customer->id,
                        'name' => $customer->name,
                        'phone' => $maskedPhone,
                    ],
                    'summary' => [
                        'total_count' => 0,
                        'total_amount' => '0.00',
                        'total_paid' => '0.00',
                        'total_discount' => '0.00',
                        'total_remaining' => '0.00',
                    ],
                    'invoices' => [],
                    'expires_at' => $shareToken->expires_at->toIso8601String(),
                ]);
            }

            return $this->successResponse([
                'mode' => 'single',
                'store' => [
                    'id' => $store->id,
                    'name' => $store->name,
                    'phone' => $store->phone,
                ],
                'customer' => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'phone' => $maskedPhone,
                ],
                'invoice' => $this->formatInvoice($invoice),
                'expires_at' => $shareToken->expires_at->toIso8601String(),
            ]);
        }

        // ===== 多账单模式 =====

        // 汇总始终基于全量计算（仅加载 discounts，轻量）
        $allInvoices = Invoice::with(['discounts'])
            ->whereIn('id', $invoiceIds)
            ->get();

        $totalAmount = $allInvoices->sum('amount');
        $totalPaid = $allInvoices->sum('paid_amount');
        $totalDiscount = $allInvoices->sum(fn ($inv) => $inv->total_discount_amount);
        $totalRemaining = $totalAmount - $totalPaid - $totalDiscount;

        $summary = [
            'total_count' => $totalCount,
            'total_amount' => number_format($totalAmount, 2, '.', ''),
            'total_paid' => number_format($totalPaid, 2, '.', ''),
            'total_discount' => number_format($totalDiscount, 2, '.', ''),
            'total_remaining' => number_format($totalRemaining, 2, '.', ''),
        ];

        // 分页控制（可选，向后兼容）
        $perPage = (int) $request->input('per_page', 10);
        $pagination = null;

        if ($page) {
            // 分页模式
            $paginator = Invoice::with(['items', 'store:id,name,phone', 'customer:id,name,phone', 'discounts', 'attachments', 'createdBy:id,name'])
                ->whereIn('id', $invoiceIds)
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            $invoices = collect($paginator->items());
            $pagination = [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ];
        } else {
            // 兼容模式：全量返回（保持现有行为）
            $invoices = Invoice::with(['items', 'store:id,name,phone', 'customer:id,name,phone', 'discounts', 'attachments', 'createdBy:id,name'])
                ->whereIn('id', $invoiceIds)
                ->orderBy('created_at', 'desc')
                ->get();
        }

        $responseData = [
            'mode' => 'multiple',
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'phone' => $store->phone,
            ],
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $maskedPhone,
            ],
            'summary' => $summary,
            'invoices' => $invoices->map(fn (Invoice $inv) => $this->formatInvoice($inv))->values(),
            'expires_at' => $shareToken->expires_at->toIso8601String(),
        ];

        if ($pagination) {
            $responseData['pagination'] = $pagination;
        }

        return $this->successResponse($responseData);
    }

    /**
     * 格式化账单数据用于输出
     */
    private function formatInvoice(Invoice $invoice): array
    {
        $statusTextMap = [
            'unpaid' => '待付款',
            'partially_paid' => '部分还款',
            'paid' => '已结清',
            'overdue' => '已逾期',
        ];

        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'amount' => number_format((float) $invoice->amount, 2, '.', ''),
            'paid_amount' => number_format((float) $invoice->paid_amount, 2, '.', ''),
            'discount_amount' => number_format($invoice->total_discount_amount, 2, '.', ''),
            'remaining' => number_format($invoice->actual_remaining_amount, 2, '.', ''),
            'status' => $invoice->status,
            'status_text' => $statusTextMap[$invoice->status] ?? $invoice->status,
            'created_at' => $invoice->created_at->format('Y-m-d H:i'),
            'created_by_name' => $invoice->createdBy?->name ?? '未知',
            'description' => $invoice->description,
            'items' => $invoice->items->map(fn ($item) => [
                'name' => $item->item_name ?? '商品',
                'description' => $item->item_description,
                'quantity' => floatval($item->quantity),
                'unit_price' => number_format($item->unit_price, 2, '.', ''),
                'subtotal' => number_format($item->subtotal, 2, '.', ''),
            ])->values(),
            'attachments' => $invoice->attachments->map(fn ($att) => [
                'id' => $att->id,
                'url' => $att->url, // 使用访问器自动生成 URL
                'thumbnail_url' => $att->url,
                'file_name' => $att->original_filename,
                'file_type' => $att->mime_type,
            ])->values(),
        ];
    }

    /**
     * 电话号码脱敏
     */
    private function maskPhone(?string $phone): ?string
    {
        if (! $phone || strlen($phone) < 7) {
            return $phone;
        }

        return substr($phone, 0, 3).'****'.substr($phone, -4);
    }
}
