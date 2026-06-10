<?php

declare(strict_types=1);

namespace KodZero\POSMall\Console;

use Illuminate\Console\Command;
use KodZero\POSMall\Classes\Benchmark\LoadBenchmark;
use KodZero\POSMall\Classes\Security\BackendAdminSafety;

class LoadBenchmarkCommand extends Command
{
    private const SUPPORTED_TARGETS = [1000, 5000, 10000, 50000, 100000, 200000, 300000];

    protected $signature = 'posmall:load-benchmark
        {target=10000 : Target load product count}
        {--iterations=10 : Benchmark iterations}
        {--no-seed : Run benchmarks against existing load data}
        {--with-images : Attach one existing local image metadata row to each synthetic product}
        {--purge : Remove only POSMall load benchmark data and exit}
        {--force : Confirm local load data replacement}';

    protected $description = 'Generate a local PostgreSQL load-test catalog and benchmark POSMall catalog hot paths.';

    public function handle(LoadBenchmark $benchmark): int
    {
        app(BackendAdminSafety::class)->assertRealBackendSuperuserAvailable('posmall:load-benchmark before benchmark data changes');

        if ($this->option('purge')) {
            $benchmark->purge();
            $this->info('POSMall load benchmark data purged.');

            return 0;
        }

        $target = (int)$this->argument('target');
        $iterations = max(1, (int)$this->option('iterations'));

        if (!in_array($target, self::SUPPORTED_TARGETS, true)) {
            $this->error('Supported targets are 1000, 5000, 10000, 50000, 100000, 200000 and 300000.');

            return 1;
        }

        if (!$this->option('force')) {
            $this->error('This command replaces existing POSMall load benchmark data. Re-run with --force.');

            return 1;
        }

        $run = $benchmark->run($target, $iterations, !$this->option('no-seed'), (bool)$this->option('with-images'));

        $this->table(['Metric', 'Value'], [
            ['Run ID', $run->id],
            ['Status', $run->status],
            ['Products', $run->actual_products],
            ['Services', $run->actual_services],
            ['Index rows', $run->actual_index_rows],
            ['Seed seconds', $run->seed_seconds ?? '-'],
            ['Benchmark seconds', $run->benchmark_seconds],
            ['Category avg ms', $run->category_avg_ms],
            ['Filtered avg ms', $run->filtered_avg_ms],
            ['Search avg ms', $run->search_avg_ms],
            ['Peak memory MB', $run->memory_peak_mb],
        ]);

        return $run->status === $run::STATUS_PASSED ? 0 : 1;
    }
}
