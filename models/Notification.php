<?php

declare(strict_types=1);

namespace KodZero\POSMall\Models;

use Illuminate\Support\Facades\Cache;
use LogicException;
use Model;
use October\Rain\Database\Traits\Sortable;
use October\Rain\Database\Traits\Validation;
use Schema;

class Notification extends Model
{
    use Validation;
    use Sortable;

    public const CACHE_KEY = 'posmall.enabled.notifications';

    public $table = 'kodzero_posmall_notifications';

    public $rules = [
        'name'     => 'required',
        'code'     => 'required|unique:kodzero_posmall_notifications,code',
        'template' => 'required',
    ];

    public $casts = [
        'enabled' => 'boolean',
    ];

    public $fillable = [
        'enabled',
        'code',
        'name',
        'description',
        'template',
    ];

    public static function getEnabled()
    {
        if (! Schema::hasTable('kodzero_posmall_notifications')) {
            return collect([]);
        }

        return Cache::rememberForever(self::CACHE_KEY, fn () => Notification::where('enabled', true)->get()->pluck('template', 'code'));
    }

    public function afterSave()
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function beforeDeleting()
    {
        throw new LogicException('POSMall: Notifications cannot be deleted.');
    }
}
