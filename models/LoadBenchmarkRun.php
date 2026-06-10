<?php

declare(strict_types=1);

namespace KodZero\POSMall\Models;

use Model;

class LoadBenchmarkRun extends Model
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_PASSED = 'passed';
    public const STATUS_ERROR = 'error';

    public $table = 'kodzero_posmall_load_benchmark_runs';

    public $fillable = [
        'target_records',
        'actual_products',
        'actual_services',
        'actual_index_rows',
        'iterations',
        'status',
        'started_at',
        'finished_at',
        'duration_seconds',
        'seed_seconds',
        'benchmark_seconds',
        'category_avg_ms',
        'filtered_avg_ms',
        'search_avg_ms',
        'memory_peak_mb',
        'metrics',
        'explain_plans',
        'error_output',
    ];

    public $jsonable = [
        'metrics',
        'explain_plans',
    ];

    public $casts = [
        'target_records' => 'integer',
        'actual_products' => 'integer',
        'actual_services' => 'integer',
        'actual_index_rows' => 'integer',
        'iterations' => 'integer',
        'duration_seconds' => 'integer',
        'seed_seconds' => 'integer',
        'benchmark_seconds' => 'integer',
        'category_avg_ms' => 'float',
        'filtered_avg_ms' => 'float',
        'search_avg_ms' => 'float',
        'memory_peak_mb' => 'float',
    ];

    protected $dates = [
        'started_at',
        'finished_at',
        'created_at',
        'updated_at',
    ];
}
