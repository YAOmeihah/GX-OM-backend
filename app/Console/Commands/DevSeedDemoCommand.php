<?php

namespace App\Console\Commands;

use Database\Seeders\DevDemoSeeder;
use Illuminate\Console\Command;

class DevSeedDemoCommand extends Command
{
    protected $signature = 'dev:seed-demo
        {--clean : Remove demo data only}
        {--force : Allow running outside local/testing}';

    protected $description = 'Clean and rebuild local demo data for backend development';

    public function handle(DevDemoSeeder $seeder): int
    {
        $env = config('app.env');
        if (! in_array($env, ['local', 'testing'], true) && ! $this->option('force')) {
            $this->error('Refusing to seed demo data outside local/testing. Use --force to override.');

            return self::FAILURE;
        }

        if ($this->option('clean')) {
            $summary = $seeder->cleanDemoData();
            $this->info('Demo data cleaned');
        } else {
            $seeder->cleanDemoData();
            $summary = $seeder->seedDemoData();
            $this->info('Demo data seeded');
        }

        foreach ($summary as $label => $count) {
            $this->line("{$label}: {$count}");
        }

        return self::SUCCESS;
    }
}
