<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\User;

use Illuminate\Support\Facades\Schema;
use RainLab\User\Models\User;
use RainLab\User\Models\UserGroup;

class PosmallUserGroup
{
    public const CODE = 'posmall';

    public static function get(): UserGroup
    {
        return UserGroup::firstOrCreate(
            ['code' => self::CODE],
            [
                'name' => 'POSMall',
                'description' => 'Users created or used by POSMall storefront flows.',
            ]
        );
    }

    public static function attach(User $user): void
    {
        self::attachGroupIds($user, [self::get()->id]);
    }

    public static function markOwned(User $user): void
    {
        if (!Schema::hasColumn($user->getTable(), 'kodzero_posmall_owned_user')) {
            return;
        }

        $user->kodzero_posmall_owned_user = true;
        $user->save();
    }

    public static function isOwned(User $user): bool
    {
        return Schema::hasColumn($user->getTable(), 'kodzero_posmall_owned_user')
            && (bool)$user->kodzero_posmall_owned_user;
    }

    public static function attachGroupIds(User $user, array $groupIds): void
    {
        $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds))));

        if ($groupIds === []) {
            return;
        }

        $relation = $user->groups();

        if (method_exists($relation, 'syncWithoutDetaching')) {
            $relation->syncWithoutDetaching($groupIds);
            return;
        }

        foreach ($groupIds as $groupId) {
            if (!$relation->where('user_groups.id', $groupId)->exists()) {
                $relation->attach($groupId);
            }
        }
    }
}
