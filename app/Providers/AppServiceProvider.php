<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Invoice;
use App\Observers\InvoiceObserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 注册 InvoiceObserver 监听 Invoice 模型的事件
        // 自动更新客户在对应门店的统计信息（总欠款、最后交易时间）
        Invoice::observe(InvoiceObserver::class);
    }
}
