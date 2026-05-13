<?php

namespace Tests\Unit;

use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;
use App\Http\Requests\Payment\StorePaymentRequest;
use App\Http\Requests\Payment\AllocatePaymentRequest;
use App\Http\Requests\Invoice\StoreInvoiceRequest;
use App\Http\Requests\Invoice\UpdateInvoiceRequest;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Models\User;
use App\Models\Store;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

/**
 * 测试 FormRequest 验证规则
 */
class FormRequestTest extends TestCase
{
    use RefreshDatabase, CreatesTestUsers;

    protected User $admin;
    protected User $storeOwner;
    protected Store $store;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRolesExist();

        $this->store = Store::factory()->create();
        $this->admin = $this->createAdmin();
        $this->storeOwner = $this->createStoreOwner([], $this->store);
        $this->customer = Customer::factory()->create();
    }

    // ===================== StorePaymentRequest 测试 =====================

    /** @test */
    public function store_payment_request_validates_required_fields()
    {
        $request = new StorePaymentRequest();
        $rules = $request->rules();

        // 检查必填字段
        $this->assertArrayHasKey('store_id', $rules);
        $this->assertArrayHasKey('customer_id', $rules);
        $this->assertArrayHasKey('amount', $rules);
        $this->assertArrayHasKey('payment_date', $rules);
        $this->assertArrayHasKey('payment_method', $rules);

        // 验证store_id规则包含required
        $this->assertStringContainsString('required', $rules['store_id']);
        $this->assertStringContainsString('exists:stores,id', $rules['store_id']);
    }

    /** @test */
    public function store_payment_request_validates_amount()
    {
        $rules = (new StorePaymentRequest())->rules();

        // amount 应该是必填、数值、最小0.01
        $this->assertStringContainsString('required', $rules['amount']);
        $this->assertStringContainsString('numeric', $rules['amount']);
        $this->assertStringContainsString('min:0.01', $rules['amount']);
    }

    /** @test */
    public function store_payment_request_validates_payment_method()
    {
        $rules = (new StorePaymentRequest())->rules();

        // payment_method 应该在规则中
        $this->assertArrayHasKey('payment_method', $rules);

        // 规则可能是字符串或数组，检查是否包含 'in' 规则
        $rule = $rules['payment_method'];
        $hasInRule = false;

        if (is_array($rule)) {
            foreach ($rule as $r) {
                // 检查 Rule::in() 对象
                if ($r instanceof \Illuminate\Validation\Rules\In) {
                    $hasInRule = true;
                    break;
                }
                // 检查字符串规则
                if (is_string($r) && str_starts_with($r, 'in:')) {
                    $hasInRule = true;
                    break;
                }
            }
        } else {
            $hasInRule = is_string($rule) && str_contains($rule, 'in:');
        }

        $this->assertTrue($hasInRule, 'payment_method should contain an "in" validation rule');
    }

    /** @test */
    public function store_payment_request_validates_optional_discount_data()
    {
        $rules = (new StorePaymentRequest())->rules();

        // discount_data 是可选数组
        $this->assertArrayHasKey('discount_data', $rules);
    }

    // ===================== StoreInvoiceRequest 测试 =====================

    /** @test */
    public function store_invoice_request_validates_required_fields()
    {
        $request = new StoreInvoiceRequest();
        $rules = $request->rules();

        $this->assertArrayHasKey('store_id', $rules);
        $this->assertArrayHasKey('customer_id', $rules);
        $this->assertArrayHasKey('invoice_date', $rules);
    }

    /** @test */
    public function store_invoice_request_validates_items_array()
    {
        $rules = (new StoreInvoiceRequest())->rules();

        // items 是可选数组
        $this->assertArrayHasKey('items', $rules);
        $this->assertArrayHasKey('items.*.quantity', $rules);
        $this->assertArrayHasKey('items.*.unit_price', $rules);
    }

    // ===================== StoreCustomerRequest 测试 =====================

    /** @test */
    public function store_customer_request_validates_name()
    {
        $rules = (new StoreCustomerRequest())->rules();

        $this->assertArrayHasKey('name', $rules);
        $this->assertStringContainsString('required', $rules['name']);
        $this->assertStringContainsString('max:255', $rules['name']);
    }

    /** @test */
    public function store_customer_request_validates_email_format()
    {
        $rules = (new StoreCustomerRequest())->rules();

        $this->assertArrayHasKey('email', $rules);
        $this->assertStringContainsString('email', $rules['email']);
    }

    // ===================== 验证器集成测试 =====================

    /** @test */
    public function validates_valid_payment_data()
    {
        $data = [
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'amount' => 1000.00,
            'payment_date' => now()->format('Y-m-d'),
            'payment_method' => 'cash',
        ];

        $request = new StorePaymentRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->fails());
    }

    /** @test */
    public function fails_validation_with_invalid_amount()
    {
        $data = [
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'amount' => -100, // 无效的负数
            'payment_date' => now()->format('Y-m-d'),
            'payment_method' => 'cash',
        ];

        $request = new StorePaymentRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('amount', $validator->errors()->toArray());
    }

    /** @test */
    public function fails_validation_with_invalid_payment_method()
    {
        $data = [
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'amount' => 1000.00,
            'payment_date' => now()->format('Y-m-d'),
            'payment_method' => 'invalid_method', // 无效的支付方式
        ];

        $request = new StorePaymentRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('payment_method', $validator->errors()->toArray());
    }

    /** @test */
    public function validates_valid_customer_data()
    {
        $data = [
            'name' => '张三',
            'phone' => '13800138000',
            'email' => 'zhangsan@example.com',
        ];

        $request = new StoreCustomerRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->fails());
    }

    /** @test */
    public function fails_validation_with_invalid_email()
    {
        $data = [
            'name' => '张三',
            'email' => 'not_an_email', // 无效的邮箱格式
        ];

        $request = new StoreCustomerRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('email', $validator->errors()->toArray());
    }

    /** @test */
    public function validates_valid_invoice_data()
    {
        $data = [
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'amount' => 500.00,
            'invoice_date' => now()->format('Y-m-d'),
        ];

        $request = new StoreInvoiceRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->fails());
    }

    /** @test */
    public function validates_invoice_with_items()
    {
        $data = [
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->format('Y-m-d'),
            'items' => [
                [
                    'item_name' => '商品A',
                    'quantity' => 2,
                    'unit_price' => 100.00,
                ],
                [
                    'item_name' => '商品B',
                    'quantity' => 1,
                    'unit_price' => 300.00,
                ],
            ],
        ];

        $request = new StoreInvoiceRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->fails());
    }
}
