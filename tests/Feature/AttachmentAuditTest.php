<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttachmentAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Store $store;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => '系统管理员']);
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($adminRole);
        Sanctum::actingAs($this->admin);

        $this->store = Store::factory()->create();
        $this->customer = Customer::factory()->create(['store_id' => $this->store->id]);
    }

    public function test_invoice_attachment_audit_log_includes_business_store_id(): void
    {
        $invoice = Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'created_by' => $this->admin->id,
        ]);

        $attachment = $this->createAttachment(Invoice::class, $invoice->id);

        $log = $this->latestAttachmentAuditLog($attachment);

        $this->assertSame('store', $log->scope_type);
        $this->assertSame($this->store->id, $log->business_store_id);
    }

    public function test_payment_attachment_audit_log_includes_business_store_id(): void
    {
        $payment = Payment::factory()->create([
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'received_by' => $this->admin->id,
        ]);

        $attachment = $this->createAttachment(Payment::class, $payment->id);

        $log = $this->latestAttachmentAuditLog($attachment);

        $this->assertSame('store', $log->scope_type);
        $this->assertSame($this->store->id, $log->business_store_id);
    }

    private function createAttachment(string $attachableType, int $attachableId): Attachment
    {
        return Attachment::create([
            'attachable_type' => $attachableType,
            'attachable_id' => $attachableId,
            'original_filename' => 'receipt.jpg',
            'stored_filename' => 'receipt.jpg',
            'file_path' => 'attachments/test/'.$attachableId.'/receipt.jpg',
            'file_size' => 1024,
            'mime_type' => 'image/jpeg',
            'uploaded_by' => $this->admin->id,
        ]);
    }

    private function latestAttachmentAuditLog(Attachment $attachment): AuditLog
    {
        return AuditLog::where('auditable_type', Attachment::class)
            ->where('auditable_id', $attachment->id)
            ->where('action', AuditLog::ACTION_CREATE)
            ->latest('id')
            ->firstOrFail();
    }
}
