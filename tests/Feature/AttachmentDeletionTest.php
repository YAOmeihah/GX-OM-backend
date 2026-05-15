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
use Mockery;
use Tests\TestCase;

class AttachmentDeletionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.attachment_disk' => 's3-compat']);

        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => '系统管理员']);
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($adminRole);
        Sanctum::actingAs($this->admin);

        $store = Store::factory()->create();
        $customer = Customer::factory()->create(['store_id' => $store->id]);
        $this->invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_destroy_deletes_storage_object_once(): void
    {
        $attachment = $this->createAttachment('attachments/test/delete-once.jpg');

        $disk = Mockery::mock();
        $disk->shouldReceive('delete')
            ->once()
            ->with($attachment->file_path)
            ->andReturn(true);
        Storage::shouldReceive('disk')
            ->once()
            ->with('s3-compat')
            ->andReturn($disk);

        $response = $this->deleteJson("/api/attachments/{$attachment->id}");

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => '附件删除成功',
            ]);

        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
    }

    public function test_destroy_does_not_delete_storage_object_when_path_is_still_referenced(): void
    {
        $filePath = 'attachments/test/shared.jpg';
        $attachment = $this->createAttachment($filePath);
        $otherAttachment = $this->createAttachment($filePath);

        Storage::shouldReceive('disk')->never();

        $response = $this->deleteJson("/api/attachments/{$attachment->id}");

        $response->assertOk();

        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
        $this->assertDatabaseHas('attachments', ['id' => $otherAttachment->id, 'file_path' => $filePath]);
    }

    private function createAttachment(string $filePath): Attachment
    {
        return Attachment::create([
            'attachable_type' => Invoice::class,
            'attachable_id' => $this->invoice->id,
            'original_filename' => basename($filePath),
            'stored_filename' => basename($filePath),
            'file_path' => $filePath,
            'file_size' => 1024,
            'mime_type' => 'image/jpeg',
            'uploaded_by' => $this->admin->id,
        ]);
    }
}
