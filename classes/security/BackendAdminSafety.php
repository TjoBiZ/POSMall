<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class BackendAdminSafety
{
    public function assertRealBackendSuperuserAvailable(string $operation): void
    {
        if (!Schema::hasTable('backend_users')) {
            return;
        }

        $query = DB::table('backend_users')
            ->select('login')
            ->where('is_superuser', true)
            ->where('is_activated', true);

        if (Schema::hasColumn('backend_users', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        $logins = $query
            ->pluck('login')
            ->map(fn ($login) => (string)$login)
            ->all();

        if (app()->runningUnitTests() && $logins === []) {
            return;
        }

        foreach ($logins as $login) {
            if (!str_starts_with($login, 'dusk_')) {
                return;
            }
        }

        throw new RuntimeException(sprintf(
            'POSMall safety guard stopped %s: backend_users has no active non-Dusk superuser. Restore the working OctoberCMS admin before running local seed, purge, test or benchmark commands.',
            $operation
        ));
    }
}
