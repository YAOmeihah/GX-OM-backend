<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttachmentUploadIntentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $otherAdmin;

    private Invoice $invoice;

    private Invoice $otherInvoice;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'filesystems.disks.s3-compat.key' => 'test-access-key',
            'filesystems.disks.s3-compat.secret' => 'test-secret-key',
            'filesystems.disks.s3-compat.region' => 'auto',
            'filesystems.disks.s3-compat.bucket' => 'test-bucket',
            'filesystems.disks.s3-compat.endpoint' => 'https://s3.example.com',
            'filesystems.disks.s3-compat.url' => 'https://cdn.example.com/test-bucket',
        ]);

        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => '系统管理员']);

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($adminRole);

        $this->otherAdmin = User::factory()->create();
        $this->otherAdmin->roles()->attach($adminRole);

        $store = Store::factory()->create();
        $customer = Customer::factory()->create(['store_id' => $store->id]);

        $this->invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'created_by' => $this->admin->id,
        ]);

        $this->otherInvoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_confirm_upload_rejects_path_without_matching_intent(): void
    {
        Sanctum::actingAs($this->admin);
        Storage::fake('s3-compat');

        $payload = $this->confirmPayload([
            'file_path' => 'attachments/invoices/2026/05/'.$this->invoice->id.'/manual_invoice.jpg',
        ]);
        Storage::disk('s3-compat')->put($payload['file_path'], 'uploaded-file');

        $response = $this->postJson('/api/attachments', $payload);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => '上传凭证无效或已过期',
            ]);

        $this->assertDatabaseMissing('attachments', ['file_path' => $payload['file_path']]);
    }

    public function test_confirm_upload_rejects_intent_for_different_entity(): void
    {
        Sanctum::actingAs($this->admin);

        $presigned = $this->requestPresignedUpload($this->invoice);
        $filePath = $presigned->json('data.file_path');

        Storage::fake('s3-compat');
        Storage::disk('s3-compat')->put($filePath, 'uploaded-file');

        $response = $this->postJson('/api/attachments', $this->confirmPayload([
            'attachable_id' => $this->otherInvoice->id,
            'file_path' => $filePath,
        ]));

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => '上传凭证无效或已过期',
            ]);

        $this->assertDatabaseMissing('attachments', ['file_path' => $filePath]);
    }

    public function test_confirm_upload_rejects_intent_for_different_user(): void
    {
        Sanctum::actingAs($this->admin);

        $presigned = $this->requestPresignedUpload($this->invoice);
        $filePath = $presigned->json('data.file_path');

        Storage::fake('s3-compat');
        Storage::disk('s3-compat')->put($filePath, 'uploaded-file');

        Sanctum::actingAs($this->otherAdmin);

        $response = $this->postJson('/api/attachments', $this->confirmPayload([
            'file_path' => $filePath,
        ]));

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => '上传凭证无效或已过期',
            ]);

        $this->assertDatabaseMissing('attachments', ['file_path' => $filePath]);
    }

    public function test_confirm_upload_consumes_matching_intent_and_creates_attachment(): void
    {
        Sanctum::actingAs($this->admin);

        $presigned = $this->requestPresignedUpload($this->invoice);
        $filePath = $presigned->json('data.file_path');

        Storage::fake('s3-compat');
        Storage::disk('s3-compat')->put($filePath, 'uploaded-file');

        $response = $this->postJson('/api/attachments', $this->confirmPayload([
            'file_path' => $filePath,
        ]));

        $response->assertCreated()
            ->assertJson([
                'success' => true,
                'message' => '附件上传成功',
            ]);

        $this->assertDatabaseHas('attachments', [
            'attachable_type' => Invoice::class,
            'attachable_id' => $this->invoice->id,
            'file_path' => $filePath,
            'uploaded_by' => $this->admin->id,
        ]);

        $this->assertDatabaseHas('attachment_upload_intents', [
            'file_path' => $filePath,
            'uploaded_by' => $this->admin->id,
        ]);

        $this->assertNotNull(
            \DB::table('attachment_upload_intents')->where('file_path', $filePath)->value('consumed_at')
        );
    }

    public function test_serialized_attachment_keeps_long_lived_url_fields(): void
    {
        Sanctum::actingAs($this->admin);

        $presigned = $this->requestPresignedUpload($this->invoice, [
            'filename' => 'receipt.png',
            'mime_type' => 'image/png',
        ]);
        $filePath = $presigned->json('data.file_path');

        Storage::fake('s3-compat');
        Storage::disk('s3-compat')->put($filePath, 'uploaded-file');

        $response = $this->postJson('/api/attachments', $this->confirmPayload([
            'file_path' => $filePath,
            'original_filename' => 'receipt.png',
            'mime_type' => 'image/png',
        ]));

        $response->assertCreated();

        $attachment = Attachment::where('file_path', $filePath)->firstOrFail();
        $response->assertJsonPath('data.url', $attachment->url);
        $response->assertJsonPath('data.thumbnail_url', $attachment->thumbnail_url);
        $this->assertStringStartsWith('https://cdn.example.com/test-bucket/', $response->json('data.url'));
    }

    private function requestPresignedUpload(Invoice $invoice, array $overrides = [])
    {
        $response = $this->postJson('/api/attachments/presigned-url', array_merge([
            'attachable_type' => 'invoice',
            'attachable_id' => $invoice->id,
            'filename' => 'invoice.jpg',
            'file_size' => 1024,
            'mime_type' => 'image/jpeg',
        ], $overrides));

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => '预签名URL生成成功',
            ]);

        return $response;
    }

    private function confirmPayload(array $overrides = []): array
    {
        return array_merge([
            'attachable_type' => 'invoice',
            'attachable_id' => $this->invoice->id,
            'file_path' => 'attachments/invoices/2026/05/'.$this->invoice->id.'/invoice.jpg',
            'original_filename' => 'invoice.jpg',
            'file_size' => 1024,
            'mime_type' => 'image/jpeg',
        ], $overrides);
    }
}
