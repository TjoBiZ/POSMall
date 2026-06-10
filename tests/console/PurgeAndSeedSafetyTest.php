<?php

declare(strict_types=1);

namespace KodZero\POSMall\Tests\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RainLab\User\Models\User;
use RainLab\User\Models\UserGroup;
use KodZero\POSMall\Classes\User\PosmallUserGroup;
use KodZero\POSMall\Models\Customer;
use KodZero\POSMall\Tests\PluginTestCase;

class PurgeAndSeedSafetyTest extends PluginTestCase
{
    public function test_purge_requires_an_explicit_group_even_with_force(): void
    {
        $userCount = DB::table('users')->count();

        $this->assertSame(1, Artisan::call('posmall:purge', ['--force' => true]));
        $this->assertSame($userCount, DB::table('users')->count());
    }

    public function test_posmall_user_purge_keeps_shared_rainlab_users(): void
    {
        $posmallGroup = PosmallUserGroup::get();
        $sharedGroup = UserGroup::firstOrCreate([
            'code' => 'shared-site-members',
        ], [
            'name' => 'Shared Site Members',
            'description' => 'Non-POSMall test group.',
        ]);

        $exclusiveUser = User::where('email', 'normal_customer@example.tld')->firstOrFail();
        $sharedUser = User::where('email', 'gold_customer@example.tld')->firstOrFail();
        DB::table('users')
            ->where('id', $exclusiveUser->id)
            ->update(['kodzero_posmall_owned_user' => true]);
        $sharedUser->groups()->syncWithoutDetaching([$sharedGroup->id]);

        $this->assertTrue($exclusiveUser->groups()->where('user_groups.id', $posmallGroup->id)->exists());
        $this->assertTrue($sharedUser->groups()->where('user_groups.id', $posmallGroup->id)->exists());

        $this->assertSame(0, Artisan::call('posmall:purge', [
            '--customers' => true,
            '--posmall-users' => true,
            '--force' => true,
        ]));

        $this->assertFalse(User::where('id', $exclusiveUser->id)->exists());
        $this->assertTrue(User::where('id', $sharedUser->id)->exists());
        $this->assertFalse(DB::table('users_groups')
            ->where('user_id', $sharedUser->id)
            ->where('user_group_id', $posmallGroup->id)
            ->exists());
        $this->assertTrue(DB::table('users_groups')
            ->where('user_id', $sharedUser->id)
            ->where('user_group_id', $sharedGroup->id)
            ->exists());
        $this->assertSame(0, Customer::count());
    }

    public function test_posmall_user_purge_keeps_existing_group_only_users_without_ownership_marker(): void
    {
        $posmallGroup = PosmallUserGroup::get();
        $user = User::create([
            'name' => 'Existing',
            'surname' => 'Member',
            'first_name' => 'Existing',
            'last_name' => 'Member',
            'email' => 'existing-posmall-group-only@example.tld',
            'login' => 'existing-posmall-group-only@example.tld',
            'password' => 'PurgeSafety!234',
            'password_confirmation' => 'PurgeSafety!234',
            'is_activated' => true,
            'activated_at' => now(),
        ]);
        $user->groups()->syncWithoutDetaching([$posmallGroup->id]);

        $customer = new Customer();
        $customer->user_id = $user->id;
        $customer->firstname = 'Existing';
        $customer->lastname = 'Member';
        $customer->is_guest = false;
        $customer->save();

        $this->assertSame(0, Artisan::call('posmall:purge', [
            '--posmall-users' => true,
            '--force' => true,
        ]));

        $this->assertTrue(User::where('id', $user->id)->exists());
        $this->assertFalse(DB::table('users_groups')
            ->where('user_id', $user->id)
            ->where('user_group_id', $posmallGroup->id)
            ->exists());
        $this->assertFalse(Customer::where('id', $customer->id)->exists());
    }

    public function test_core_seed_is_idempotent_and_does_not_refresh_users(): void
    {
        $before = $this->coreCounts();

        $this->assertSame(0, Artisan::call('posmall:seed', ['--force' => true]));
        $this->assertSame(0, Artisan::call('posmall:seed', ['--force' => true]));

        $after = $this->coreCounts();

        $this->assertSame($before['users'], $after['users']);
        $this->assertSame($before['currencies'], $after['currencies']);
        $this->assertSame($before['price_categories'], $after['price_categories']);
        $this->assertSame($before['payment_methods'], $after['payment_methods']);
        $this->assertSame($before['shipping_methods'], $after['shipping_methods']);
        $this->assertSame($before['order_states'], $after['order_states']);
        $this->assertSame($before['notifications'], $after['notifications']);
    }

    private function coreCounts(): array
    {
        return [
            'users' => DB::table('users')->count(),
            'currencies' => DB::table('kodzero_posmall_currencies')->count(),
            'price_categories' => DB::table('kodzero_posmall_price_categories')->count(),
            'payment_methods' => DB::table('kodzero_posmall_payment_methods')->count(),
            'shipping_methods' => DB::table('kodzero_posmall_shipping_methods')->count(),
            'order_states' => DB::table('kodzero_posmall_order_states')->count(),
            'notifications' => DB::table('kodzero_posmall_notifications')->count(),
        ];
    }
}
