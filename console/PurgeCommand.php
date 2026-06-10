<?php

declare(strict_types=1);

namespace KodZero\POSMall\Console;

use DB;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use RainLab\User\Models\UserGroup;
use System\Models\File as SystemFile;
use KodZero\POSMall\Classes\User\PosmallUserGroup;
use KodZero\POSMall\Models\Category;
use KodZero\POSMall\Models\CustomField;
use KodZero\POSMall\Models\CustomFieldOption;
use KodZero\POSMall\Models\Discount;
use KodZero\POSMall\Models\ImageSet;
use KodZero\POSMall\Models\PaymentMethod;
use KodZero\POSMall\Models\Product;
use KodZero\POSMall\Models\ProductFile;
use KodZero\POSMall\Models\Service;
use KodZero\POSMall\Models\ServiceOption;
use KodZero\POSMall\Models\ShippingMethod;
use KodZero\POSMall\Models\ShippingMethodRate;
use KodZero\POSMall\Classes\Security\BackendAdminSafety;
use KodZero\POSMall\Models\Variant;

class PurgeCommand extends Command
{
    protected $signature = '
        posmall:purge
        {--transactional : Delete orders, carts, wishlists and payment logs}
        {--orders : Delete orders and order products}
        {--carts : Delete carts and cart rows}
        {--wishlists : Delete wishlists and wishlist rows}
        {--payment-logs : Delete payment logs}
        {--customers : Delete POSMall customers, addresses and customer payment methods}
        {--catalog : Delete POSMall catalog, taxes, services, discounts, properties, brands, categories and index rows}
        {--all-posmall : Delete POSMall-owned catalog, transactional and customer data, but keep RainLab users}
        {--posmall-users : Delete only RainLab users exclusively assigned to the POSMall user group; detach POSMall group from shared users}
        {--danger-all-users : Delete all RainLab frontend users and user group links}
        {--i-understand-delete-all-users : Required together with --danger-all-users}
        {--i-understand-production-data-loss : Required for destructive purge groups in production}
        {--force : Do not ask for confirmation for selected non-dangerous purge groups}
    ';

    protected $name = 'posmall:purge';

    protected $description = 'Safely purge selected POSMall data groups without deleting shared RainLab users by default.';

    public function handle(): int
    {
        $groups = $this->selectedGroups();

        if (!$groups) {
            $this->warn('No POSMall purge group selected.');
            $this->line('Use --transactional, --customers, --catalog, --all-posmall, --posmall-users, or --danger-all-users.');
            $this->line('RainLab users are never deleted by default.');

            return 1;
        }

        if ($this->runsInProductionWithoutExplicitAcknowledgement()) {
            $this->error('Refusing to purge POSMall data in production without --i-understand-production-data-loss.');

            return 1;
        }

        if (!$this->confirmSelectedGroups($groups)) {
            return 0;
        }

        app(BackendAdminSafety::class)->assertRealBackendSuperuserAvailable('posmall:purge before selected cleanup');

        if ($groups['danger_all_users']) {
            $this->purgeAllRainLabUsers();
        }

        if ($groups['posmall_users'] || $groups['customers']) {
            $this->purgeCustomerDependentData($groups);
        }

        if ($groups['catalog']) {
            $this->purgeCatalog();
        }

        if ($groups['orders']) {
            $this->truncateTables([
                'kodzero_posmall_order_products',
                'kodzero_posmall_orders',
            ], 'Deleting orders');
        }

        if ($groups['carts']) {
            $this->truncateTables([
                'kodzero_posmall_cart_custom_field_value',
                'kodzero_posmall_cart_discount',
                'kodzero_posmall_cart_product_service_option',
                'kodzero_posmall_cart_products',
                'kodzero_posmall_carts',
            ], 'Deleting carts');
        }

        if ($groups['wishlists']) {
            $this->truncateTables([
                'kodzero_posmall_wishlist_items',
                'kodzero_posmall_wishlists',
            ], 'Deleting wishlists');
        }

        if ($groups['payment_logs']) {
            $this->truncateTables([
                'kodzero_posmall_payments_log',
            ], 'Deleting payment logs');
        }

        if ($groups['customers']) {
            $this->purgeCustomers();
        }

        if ($groups['posmall_users']) {
            $this->purgePosmallRainLabUsers();
        }

        $this->cleanupCounters($groups);
        $this->callSilent('cache:clear', []);
        app(BackendAdminSafety::class)->assertRealBackendSuperuserAvailable('posmall:purge after selected cleanup');
        $this->alert('Selected POSMall data groups have been purged.');

        return 0;
    }

    private function selectedGroups(): array
    {
        $groups = [
            'orders' => (bool)$this->option('orders'),
            'carts' => (bool)$this->option('carts'),
            'wishlists' => (bool)$this->option('wishlists'),
            'payment_logs' => (bool)$this->option('payment-logs'),
            'customers' => (bool)$this->option('customers'),
            'catalog' => (bool)$this->option('catalog'),
            'posmall_users' => (bool)$this->option('posmall-users'),
            'danger_all_users' => (bool)$this->option('danger-all-users'),
        ];

        if ($this->option('transactional')) {
            $groups['orders'] = true;
            $groups['carts'] = true;
            $groups['wishlists'] = true;
            $groups['payment_logs'] = true;
        }

        if ($this->option('all-posmall')) {
            $groups['orders'] = true;
            $groups['carts'] = true;
            $groups['wishlists'] = true;
            $groups['payment_logs'] = true;
            $groups['customers'] = true;
            $groups['catalog'] = true;
        }

        if ($groups['customers'] || $groups['catalog'] || $groups['posmall_users']) {
            $groups['orders'] = true;
            $groups['carts'] = true;
            $groups['wishlists'] = true;
            $groups['payment_logs'] = true;
        }

        if ($groups['posmall_users']) {
            $groups['customers'] = true;
        }

        return array_filter($groups) ? $groups : [];
    }

    private function confirmSelectedGroups(array $groups): bool
    {
        if ($groups['danger_all_users']) {
            if (!$this->option('i-understand-delete-all-users')) {
                $this->error('--danger-all-users requires --i-understand-delete-all-users.');
                $this->warn('This deletes every RainLab frontend user, including users unrelated to POSMall.');

                return false;
            }

            return $this->confirm('Delete every RainLab frontend user and user group link?', false);
        }

        if ($this->option('force')) {
            return true;
        }

        $selected = collect($groups)
            ->filter()
            ->keys()
            ->implode(', ');

        return $this->confirm('Purge selected POSMall data groups: ' . $selected . '?', false);
    }

    private function purgeCustomerDependentData(array $groups): void
    {
        if (!$groups['orders'] && !$groups['carts'] && !$groups['wishlists'] && !$groups['payment_logs']) {
            return;
        }

        $this->warn(' Customer purge implies transactional cleanup to avoid stale customer references.');
    }

    private function purgeCustomers(): void
    {
        $this->warn(' Deleting POSMall customers and addresses, keeping RainLab users...');

        if (Schema::hasTable('kodzero_posmall_reviews')) {
            DB::table('kodzero_posmall_reviews')->update(['customer_id' => null]);
        }

        $this->truncateTables([
            'kodzero_posmall_customer_payment_methods',
            'kodzero_posmall_addresses',
            'kodzero_posmall_customers',
        ]);
    }

    private function purgePosmallRainLabUsers(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('users_groups')) {
            return;
        }

        $posmallGroup = PosmallUserGroup::get();
        $guestGroup = method_exists(UserGroup::class, 'getGuestGroup') ? UserGroup::getGuestGroup() : null;
        $allowedGroupIds = array_filter([(int)$posmallGroup->id, $guestGroup ? (int)$guestGroup->id : null]);

        $userIds = DB::table('users_groups')
            ->where('user_group_id', $posmallGroup->id)
            ->pluck('user_id')
            ->map(fn ($id) => (int)$id)
            ->all();

        if (!$userIds) {
            return;
        }

        $deleteUserIds = [];
        $sharedUserIds = [];

        foreach ($userIds as $userId) {
            $groupIds = DB::table('users_groups')
                ->where('user_id', $userId)
                ->pluck('user_group_id')
                ->map(fn ($id) => (int)$id)
                ->all();

            $isOwned = DB::table('users')
                ->where('id', $userId)
                ->when(Schema::hasColumn('users', 'kodzero_posmall_owned_user'), function ($query) {
                    $query->where('kodzero_posmall_owned_user', true);
                }, function ($query) {
                    $query->whereRaw('1 = 0');
                })
                ->exists();

            if ($isOwned && array_diff($groupIds, $allowedGroupIds) === []) {
                $deleteUserIds[] = $userId;
            } else {
                $sharedUserIds[] = $userId;
            }
        }

        if ($deleteUserIds) {
            DB::table('users_groups')->whereIn('user_id', $deleteUserIds)->delete();
            DB::table('users')->whereIn('id', $deleteUserIds)->delete();
            $this->warn(' Deleted RainLab users exclusively owned by POSMall: ' . count($deleteUserIds));
        }

        if ($sharedUserIds) {
            DB::table('users_groups')
                ->whereIn('user_id', $sharedUserIds)
                ->where('user_group_id', $posmallGroup->id)
                ->delete();

            $this->clearPosmallUserColumns($sharedUserIds);

            $this->warn(' Kept shared RainLab users and detached POSMall group: ' . count($sharedUserIds));
        }
    }

    private function purgeAllRainLabUsers(): void
    {
        $this->warn(' Deleting every RainLab frontend user...');

        $this->truncateTables([
            'users_groups',
            'users',
        ]);
    }

    private function purgeCatalog(): void
    {
        $this->warn(' Deleting POSMall catalog, tax, service, discount, property, brand, category and index rows...');

        $this->deletePosmallSystemFiles();

        $this->truncateTables([
            'kodzero_posmall_product_accessory',
            'kodzero_posmall_product_tax',
            'kodzero_posmall_service_tax',
            'kodzero_posmall_shipping_method_tax',
            'kodzero_posmall_payment_method_tax',
            'kodzero_posmall_country_tax',
            'kodzero_posmall_state_tax',
            'kodzero_posmall_taxes',
            'kodzero_posmall_product_prices',
            'kodzero_posmall_customer_group_prices',
            'kodzero_posmall_prices',
            'kodzero_posmall_property_values',
            'kodzero_posmall_unique_property_values',
            'kodzero_posmall_product_variants',
            'kodzero_posmall_product_file_variant',
            'kodzero_posmall_product_files',
            'kodzero_posmall_product_custom_field',
            'kodzero_posmall_custom_field_options',
            'kodzero_posmall_custom_fields',
            'kodzero_posmall_product_service',
            'kodzero_posmall_service_options',
            'kodzero_posmall_services',
            'kodzero_posmall_category_product',
            'kodzero_posmall_category_property_group',
            'kodzero_posmall_property_property_group',
            'kodzero_posmall_properties',
            'kodzero_posmall_property_groups',
            'kodzero_posmall_image_sets',
            'kodzero_posmall_index',
            'kodzero_posmall_reviews',
            'kodzero_posmall_review_categories',
            'kodzero_posmall_products',
            'kodzero_posmall_discounts',
            'kodzero_posmall_brands',
            'kodzero_posmall_categories',
        ]);

        (new Category())->purgeCache();
    }

    private function runsInProductionWithoutExplicitAcknowledgement(): bool
    {
        return app()->environment('production')
            && !$this->option('i-understand-production-data-loss');
    }

    private function truncateTables(array $tables, ?string $message = null): void
    {
        $existing = collect($tables)
            ->filter(fn (string $table) => Schema::hasTable($table))
            ->values();

        if ($existing->isEmpty()) {
            return;
        }

        if ($message) {
            $this->warn(' ' . $message . '...');
        }

        DB::statement('TRUNCATE ' . $existing->map(fn (string $table) => $this->quoteIdentifier($table))->implode(', ') . ' RESTART IDENTITY');
    }

    private function cleanupCounters(array $groups): void
    {
        if (($groups['orders'] || $groups['catalog']) && Schema::hasTable('kodzero_posmall_products')) {
            Product::query()->update(['sales_count' => 0]);
        }

        if (($groups['orders'] || $groups['catalog']) && Schema::hasTable('kodzero_posmall_product_variants')) {
            Variant::query()->update(['sales_count' => 0]);
        }

        if (($groups['orders'] || $groups['catalog']) && Schema::hasTable('kodzero_posmall_discounts')) {
            Discount::query()->update(['number_of_usages' => 0]);
        }
    }

    private function deletePosmallSystemFiles(): void
    {
        if (!Schema::hasTable('system_files')) {
            return;
        }

        SystemFile::where(function ($query) {
            $query->whereIn('attachment_type', $this->posmallAttachmentTypes())
                ->orWhere('attachment_type', 'LIKE', 'KodZero\\POSMall\\%');
        })
            ->get()
            ->each
            ->delete();
    }

    private function posmallAttachmentTypes(): array
    {
        return array_filter(array_unique([
            CustomField::MORPH_KEY,
            CustomFieldOption::MORPH_KEY,
            Discount::MORPH_KEY,
            ImageSet::MORPH_KEY,
            PaymentMethod::MORPH_KEY,
            Product::MORPH_KEY,
            ProductFile::class,
            Service::class,
            ServiceOption::MORPH_KEY,
            ShippingMethod::MORPH_KEY,
            ShippingMethodRate::MORPH_KEY,
            Variant::MORPH_KEY,
        ]));
    }

    private function clearPosmallUserColumns(array $userIds): void
    {
        if (!$userIds) {
            return;
        }

        $values = [];

        if (Schema::hasColumn('users', 'kodzero_posmall_customer_group_id')) {
            $values['kodzero_posmall_customer_group_id'] = null;
        }

        if (Schema::hasColumn('users', 'kodzero_posmall_owned_user')) {
            $values['kodzero_posmall_owned_user'] = false;
        }

        if ($values) {
            DB::table('users')->whereIn('id', $userIds)->update($values);
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
