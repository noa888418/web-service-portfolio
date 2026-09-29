<?php

namespace App\Console\Commands;

use App\Demo\DemoSeeder;
use App\Demo\SeedRefused;
use Illuminate\Console\Command;
use Throwable;

final class DemoSeed extends Command
{
    protected $signature = 'demo:seed {--target=} {--publication=} {--seed-version=} {--allow} {--credentials=/run/local-demo/credentials.json}';
    protected $description = 'Explicitly seed an allowlisted local demo or isolated test database';

    public function handle(DemoSeeder $seeder): int
    {
        try {
            $result = $seeder->run((string) $this->option('target'), (string) $this->option('publication'),
                (string) $this->option('seed-version'), (bool) $this->option('allow'), (string) $this->option('credentials'));
            $this->info('Demo seed: '.$result.'.');
            return self::SUCCESS;
        } catch (Throwable $error) {
            // Console's default exception rendering may contain SQL/bindings. Do not expose it.
            $this->error('Demo seed refused: '.($error instanceof SeedRefused ? $error->getMessage() : 'operation_failed').'.');
            return self::FAILURE;
        }
    }
}
