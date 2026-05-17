<?php

namespace Tests\Feature;

use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

class DevSeedDemoCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_refuses_non_local_environment_without_force(): void
    {
        config(['app.env' => 'production']);

        $this->artisan('dev:seed-demo')
            ->expectsOutputToContain('Refusing to seed demo data outside local/testing')
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(0, Store::where('code', 'like', 'DEMO-%')->count());
    }

    public function test_clean_option_succeeds_on_empty_database(): void
    {
        $this->artisan('dev:seed-demo', ['--clean' => true])
            ->expectsOutputToContain('Demo data cleaned')
            ->assertExitCode(Command::SUCCESS);
    }
}
