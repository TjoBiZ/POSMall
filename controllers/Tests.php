<?php

declare(strict_types=1);

namespace KodZero\POSMall\Controllers;

use Artisan;
use Backend\Classes\Controller;
use BackendAuth;
use BackendMenu;
use Flash;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use KodZero\POSMall\Classes\Benchmark\LoadBenchmark;
use KodZero\POSMall\Classes\Images\CatalogImageOptimizer;
use KodZero\POSMall\Classes\PageSpeed\StorefrontAssetOptimizer;
use KodZero\POSMall\Models\LoadBenchmarkRun;
use KodZero\POSMall\Classes\Tests\TestRunner;
use KodZero\POSMall\Models\TestRun;
use KodZero\POSMall\Models\TestSettings;
use Redirect;
use Validator;

class Tests extends Controller
{
    public $requiredPermissions = [
        'kodzero.posmall.manage_tests',
    ];

    private const LOG_VIEW_LIMIT = 204800;

    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('KodZero.POSMall', 'posmall-catalogue', 'posmall-tests');
    }

    public function index(): void
    {
        $runner = new TestRunner();
        $tests = $runner->discover();

        $this->pageTitle = 'POSPOSMall Tests';
        $this->vars['backendTests'] = $tests['backend'];
        $this->vars['duskTests'] = $tests['dusk'];
        $this->vars['selectedBackendTests'] = $this->selectedPaths('backend', $runner);
        $this->vars['selectedDuskTests'] = $this->selectedPaths('dusk', $runner);
        $this->vars['settings'] = $this->settingsVars();
        $this->vars['schemaMissing'] = !Schema::hasTable('kodzero_posmall_test_runs');
        $this->vars['runs'] = $this->vars['schemaMissing']
            ? collect()
            : TestRun::orderBy('started_at', 'desc')->take(25)->get();
        $this->vars['loadBenchmarkSchemaMissing'] = !Schema::hasTable('kodzero_posmall_load_benchmark_runs');
        $this->vars['loadBenchmarkRuns'] = $this->vars['loadBenchmarkSchemaMissing']
            ? collect()
            : LoadBenchmarkRun::orderBy('started_at', 'desc')->take(10)->get();
        $this->vars['loadBenchmarkEnabled'] = app()->environment(['local', 'dev', 'development', 'testing']);
        $this->vars['testUserEvents'] = $runner->latestTestUserEvents();
    }

    public function log($id): void
    {
        $run = TestRun::findOrFail($id);

        $this->pageTitle = 'POSPOSMall Test Log #' . $run->id;
        $this->vars['run'] = $run;
        $this->vars['logText'] = $this->readLogTail($run);
        $this->vars['limitKb'] = (int)(self::LOG_VIEW_LIMIT / 1024);
    }

    public function onSaveSelection()
    {
        $runner = new TestRunner();
        $discovered = $runner->discover();
        $settings = TestSettings::instance();

        $settings->selected_backend_tests = $this->allowedPostedPaths('backend_tests', $discovered['backend']);
        $settings->selected_dusk_tests = $this->allowedPostedPaths('dusk_tests', $discovered['dusk']);
        $settings->selection_initialized = true;
        $settings->save();

        Flash::success('Test selection saved.');

        return Redirect::refresh();
    }

    public function onSaveSchedule()
    {
        $data = post('schedule', []);

        Validator::make($data, [
            'frequency'     => 'required|in:daily,weekly,monthly,disabled',
            'time_of_day'    => 'required|date_format:H:i',
            'failure_email'  => 'nullable|email',
        ])->validate();

        $settings = TestSettings::instance();
        $settings->schedule_enabled = (bool)data_get($data, 'schedule_enabled', false);
        $settings->frequency = (string)data_get($data, 'frequency', 'daily');
        $settings->time_of_day = (string)data_get($data, 'time_of_day', '03:00');
        $settings->failure_email = trim((string)data_get($data, 'failure_email', ''));
        $settings->notify_only_on_failure = (bool)data_get($data, 'notify_only_on_failure', false);
        $settings->save();

        Flash::success('Schedule settings saved.');

        return Redirect::refresh();
    }

    public function onRunSelected()
    {
        $this->onSaveSelection();

        return $this->runCommand(false);
    }

    public function onRunAll()
    {
        return $this->runCommand(true);
    }

    public function onRunLoadBenchmark10000()
    {
        return $this->runLoadBenchmark(10000);
    }

    public function onRunLoadBenchmark100000()
    {
        return $this->runLoadBenchmark(100000);
    }

    public function onPurgeLoadBenchmark()
    {
        if (!app()->environment(['local', 'dev', 'development', 'testing'])) {
            Flash::error('Load benchmarks are available only in local/dev/testing environments.');

            return Redirect::refresh();
        }

        app(LoadBenchmark::class)->purge();
        Flash::success('POSMall load benchmark data purged.');

        return Redirect::refresh();
    }

    public function onOptimizeImageCache()
    {
        if (!app()->environment(['local', 'dev', 'development', 'testing'])) {
            Flash::error('Image cache rebuild is available only in local/dev/testing environments.');

            return Redirect::refresh();
        }

        $result = app(CatalogImageOptimizer::class)->optimize(['all']);

        Flash::success(sprintf(
            'POSMall image cache rebuilt: %d packaged source images, %d uploaded attachment images, %d generated files.',
            $result['source_count'],
            $result['attached_count'],
            $result['created_files']
        ));

        return Redirect::refresh();
    }

    public function onOptimizeStorefrontAssets()
    {
        if (!app()->environment(['local', 'dev', 'development', 'testing'])) {
            Flash::error('Storefront asset optimization is available only in local/dev/testing environments.');

            return Redirect::refresh();
        }

        $result = app(StorefrontAssetOptimizer::class)->optimize();
        $summary = collect($result['assets'])
            ->map(fn (array $asset, string $type): string => sprintf(
                '%s %s -> %s bytes',
                strtoupper($type),
                $asset['status'] ?? 'unknown',
                $asset['compiled_bytes'] ?? '-'
            ))
            ->implode('; ');

        Flash::success(sprintf(
            'POSMall PageSpeed storefront assets rebuilt with %s: %s',
            $result['builder'] ?? 'unknown builder',
            $summary
        ));

        return Redirect::refresh();
    }

    private function runCommand(bool $all)
    {
        if (!Schema::hasTable('kodzero_posmall_test_runs')) {
            Flash::error('The kodzero_posmall_test_runs table is missing. Run October updates before starting tests.');

            return Redirect::refresh();
        }

        $params = [
            '--trigger' => 'manual',
        ];

        if ($all) {
            $params['--all'] = true;
        }

        $user = BackendAuth::getUser();
        if ($user) {
            $params['--backend-user-id'] = $user->id;
        }

        $exit = Artisan::call('posmall:tests:run', $params);

        if ($exit === 0) {
            Flash::success('Test run completed. See Logs for details.');
        } else {
            Flash::error('Test run finished with failures. See Logs for details.');
        }

        return Redirect::refresh();
    }

    private function runLoadBenchmark(int $target)
    {
        if (!app()->environment(['local', 'dev', 'development', 'testing'])) {
            Flash::error('Load benchmarks are available only in local/dev/testing environments.');

            return Redirect::refresh();
        }

        if (!Schema::hasTable('kodzero_posmall_load_benchmark_runs')) {
            Flash::error('The kodzero_posmall_load_benchmark_runs table is missing. Run October plugin updates first.');

            return Redirect::refresh();
        }

        $exit = Artisan::call('posmall:load-benchmark', [
            'target' => $target,
            '--iterations' => 10,
            '--force' => true,
            '--with-images' => true,
        ]);

        if ($exit === 0) {
            Flash::success(sprintf('Load benchmark for %,d records completed.', $target));
        } else {
            Flash::error(sprintf('Load benchmark for %,d records failed. Check the benchmark table.', $target));
        }

        return Redirect::refresh();
    }

    private function selectedPaths(string $type, TestRunner $runner): array
    {
        $key = $type === 'backend' ? 'selected_backend_tests' : 'selected_dusk_tests';
        $selected = (array)TestSettings::get($key, []);

        if ($selected === [] && !TestSettings::get('selection_initialized', false)) {
            return $runner->defaultSelectedPaths($type);
        }

        return $selected;
    }

    private function settingsVars(): array
    {
        return [
            'schedule_enabled'       => (bool)TestSettings::get('schedule_enabled', true),
            'frequency'              => (string)TestSettings::get('frequency', 'daily'),
            'time_of_day'            => (string)TestSettings::get('time_of_day', '03:00'),
            'failure_email'          => (string)TestSettings::get('failure_email', ''),
            'notify_only_on_failure' => (bool)TestSettings::get('notify_only_on_failure', true),
        ];
    }

    private function allowedPostedPaths(string $field, array $discovered): array
    {
        $posted = post($field, []);
        $posted = is_array($posted) ? $posted : [];
        $allowed = array_column($discovered, 'path');

        return array_values(array_intersect($posted, $allowed));
    }

    private function readLogTail(TestRun $run): string
    {
        if (!$run->log_path) {
            return '';
        }

        $path = storage_path('app/' . ltrim((string)$run->log_path, '/'));

        if (!File::exists($path)) {
            return 'Log file not found: storage/app/' . $run->log_path;
        }

        $size = File::size($path);
        $handle = fopen($path, 'rb');

        if (!$handle) {
            return 'Unable to open log file: storage/app/' . $run->log_path;
        }

        if ($size > self::LOG_VIEW_LIMIT) {
            fseek($handle, -self::LOG_VIEW_LIMIT, SEEK_END);
        }

        $contents = stream_get_contents($handle);
        fclose($handle);

        return (string)$contents;
    }
}
