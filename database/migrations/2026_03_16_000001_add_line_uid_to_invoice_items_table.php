<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('invoice_items', 'line_uid')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->string('line_uid', 36)->nullable()->after('invoice_id')->comment('明细稳定业务标识(UUID)');
            });
        }

        DB::table('invoice_items')
            ->whereNull('line_uid')
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($items) {
                foreach ($items as $item) {
                    DB::table('invoice_items')
                        ->where('id', $item->id)
                        ->update(['line_uid' => (string) Str::uuid()]);
                }
            }, 'id');

        if (DB::table('invoice_items')->whereNull('line_uid')->exists()) {
            throw new \RuntimeException('invoice_items.line_uid 回填失败，仍存在 NULL 值，请检查历史数据后重试迁移。');
        }

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->string('line_uid', 36)->nullable(false)->change();
        });

        if (! $this->hasIndex('invoice_items', 'invoice_items_invoice_line_uid_unique')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->unique(['invoice_id', 'line_uid'], 'invoice_items_invoice_line_uid_unique');
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('invoice_items', 'invoice_items_invoice_line_uid_unique')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->dropUnique('invoice_items_invoice_line_uid_unique');
            });
        }

        if (Schema::hasColumn('invoice_items', 'line_uid')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->dropColumn('line_uid');
            });
        }
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return ! empty(DB::select("SELECT name FROM sqlite_master WHERE type='index' AND name=?", [$indexName]));
        }

        return ! empty(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]));
    }
};
