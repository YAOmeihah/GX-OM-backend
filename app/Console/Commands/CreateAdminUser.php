<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CreateAdminUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:create {--email=admin@example.com} {--password=admin123456} {--name=系统管理员}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '创建系统管理员账户';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->option('email');
        $password = $this->option('password');
        $name = $this->option('name');

        $this->info('正在创建系统管理员账户...');

        try {
            DB::beginTransaction();

            // 检查是否已存在管理员角色
            $adminRole = Role::where('slug', 'admin')->first();
            if (! $adminRole) {
                $adminRole = Role::create([
                    'name' => '系统管理员',
                    'slug' => 'admin',
                    'description' => '系统管理员，拥有所有权限',
                ]);
                $this->info('✓ 创建管理员角色成功');
            } else {
                $this->info('✓ 管理员角色已存在');
            }

            // 检查是否已存在管理员用户
            $adminUser = User::where('email', $email)->first();
            if ($adminUser) {
                $this->warn('! 管理员用户已存在，正在更新密码...');
                $adminUser->update([
                    'password' => Hash::make($password),
                ]);
            } else {
                // 创建管理员用户
                $adminUser = User::create([
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make($password),
                    'email_verified_at' => now(),
                ]);
                $this->info('✓ 创建管理员用户成功');
            }

            // 为用户分配管理员角色
            if (! $adminUser->hasRole('admin')) {
                $adminUser->roles()->attach($adminRole->id);
                $this->info('✓ 分配管理员角色成功');
            } else {
                $this->info('✓ 用户已具有管理员角色');
            }

            // 创建其他必要的角色
            Role::firstOrCreate([
                'slug' => 'store_owner',
            ], [
                'name' => '店长',
                'description' => '门店管理员',
            ]);

            Role::firstOrCreate([
                'slug' => 'store_staff',
            ], [
                'name' => '店员',
                'description' => '门店员工',
            ]);

            $this->info('✓ 创建其他角色成功');

            DB::commit();

            $this->info('');
            $this->info('=== 管理员账户创建成功 ===');
            $this->info("邮箱: {$email}");
            $this->info("密码: {$password}");
            $this->info('角色: 系统管理员');
            $this->info("用户ID: {$adminUser->id}");
            $this->info('');
            $this->warn('请使用以上信息登录系统，登录后建议立即修改密码。');

            return 0;

        } catch (\Exception $e) {
            DB::rollback();
            $this->error('✗ 创建失败: '.$e->getMessage());
            $this->error('文件: '.$e->getFile().':'.$e->getLine());

            return 1;
        }
    }
}
