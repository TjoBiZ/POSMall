<?php

declare(strict_types=1);

namespace KodZero\POSMall\Models;

use Model;

class TestRun extends Model
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_PASSED = 'passed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ERROR = 'error';

    public $table = 'kodzero_posmall_test_runs';

    public $fillable = [
        'trigger',
        'backend_user_id',
        'backend_user_name',
        'status',
        'started_at',
        'finished_at',
        'duration_seconds',
        'selected_tests_count',
        'backend_tests_count',
        'dusk_tests_count',
        'selected_tests',
        'failing_tests',
        'log_path',
        'error_output',
    ];

    public $jsonable = [
        'selected_tests',
        'failing_tests',
    ];

    public $casts = [
        'selected_tests_count' => 'integer',
        'backend_tests_count'  => 'integer',
        'dusk_tests_count'     => 'integer',
        'duration_seconds'     => 'integer',
    ];

    protected $dates = [
        'started_at',
        'finished_at',
        'created_at',
        'updated_at',
    ];

    public function getStorageLogPathAttribute(): string
    {
        return $this->log_path ? 'storage/app/' . ltrim((string)$this->log_path, '/') : '';
    }
}
