<?php

declare(strict_types=1);

namespace KodZero\POSMall\Models;

use Cms\Classes\Page;
use Cms\Classes\Theme;
use Illuminate\Support\Facades\Cache;
use Model;
use October\Rain\Database\Traits\Encryptable;
use KodZero\POSMall\Models\Tax;
use Validator;

class GeneralSettings extends Model
{
    use Encryptable;

    public const INDEX_DRIVER_CACHE_KEY = 'kodzero_posmall.postgresql.index.driver';
    public const SETTINGS_CACHE_KEY = 'system::settings.kodzero_posmall_settings';
    public const SETTINGS_CODE = 'kodzero_posmall_settings';
    public const INDEX_DRIVER_DATABASE = 'database';

    protected $encryptable = [
        'usps_addresses_client_id',
        'usps_addresses_client_secret',
    ];

    /**
     * Implement behaviors for this controller.
     * @var array
     */
    public $implement = ['System.Behaviors.SettingsModel'];

    /**
     * Required settings code property.
     * @var string
     */
    public $settingsCode = self::SETTINGS_CODE;

    /**
     * Required settings YAML fields file.
     * @var string
     */
    public $settingsFields = '$/kodzero/posmall/models/settings/fields_general.yaml';

    /**
     * The validation rules for the single attributes.
     * @var array
     */
    public $rules = [
        'admin_email' => 'nullable|email',
        'order_notification_email' => 'nullable|email',
    ];

    /**
     * Hook before model is saved.
     * @return void
     */
    public function beforeSave()
    {
        $validator = Validator::make($this->value, $this->rules);
        $validator->validate();
    }

    /**
     * Hook after model has been saved.
     * @return void
     */
    public function afterSave()
    {
        Cache::forget(self::INDEX_DRIVER_CACHE_KEY);
        Cache::forget(self::SETTINGS_CACHE_KEY);
    }

    public static function getUncached(string $key, $default = null)
    {
        $value = static::query()
            ->where('item', self::SETTINGS_CODE)
            ->value('value');

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($value)) {
            return $default;
        }

        return array_get($value, $key, $default);
    }

    public static function getIndexDriver(): string
    {
        return self::INDEX_DRIVER_DATABASE;
    }

    /**
     * Get Pages by CMS Component
     * @param string $component
     * @param bool $allOnEmpty
     * @return array
     */
    public function getPagesByComponent(string $component, bool $allOnEmpty = true)
    {
        $theme = Theme::getActiveTheme();
        $pages = Page::listInTheme($theme, true);
        
        $cmsPages = [];
        
        foreach ($pages as $page) {
            if (!$page->hasComponent($component)) {
                continue;
            }
            $cmsPages[$page->baseFileName] = $page->title;
        }

        if (count($cmsPages) < 1) {
            return $allOnEmpty ? $this->allPages() : [];
        } else {
            return $cmsPages;
        }
    }

    /**
     * Return CMS Pages with [product] component
     * @return array
     */
    public function getProductPageOptions()
    {
        return $this->getPagesByComponent('product');
    }

    /**
     * Return CMS Pages with [products] component
     * @return array
     */
    public function getCategoryPageOptions()
    {
        return $this->getPagesByComponent('products');
    }

    /**
     * Return CMS Pages with [addressForm] component
     * @return array
     */
    public function getAddressPageOptions()
    {
        return $this->getPagesByComponent('addressForm');
    }

    /**
     * Return CMS Pages with [checkout] and [quickCheckout] component
     * @return array
     */
    public function getCheckoutPageOptions()
    {
        $result = array_merge(
            $this->getPagesByComponent('checkout', false),
            $this->getPagesByComponent('quickCheckout', false),
        );

        return empty($result) ? $this->allPages() : $result;
    }

    /**
     * Return CMS Pages with [myAccount] component
     * @return array
     */
    public function getAccountPageOptions()
    {
        return $this->getPagesByComponent('myAccount');
    }

    /**
     * Return CMS Pages with [cart] component
     * @return array
     */
    public function getCartPageOptions()
    {
        return $this->getPagesByComponent('cart');
    }

    public function getUsaDefaultTaxIdOptions(): array
    {
        return Tax::where('is_enabled', true)
            ->orderBy('state_code')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Tax $tax) => [$tax->id => $tax->display_name])
            ->all();
    }

    /**
     * Return all CMS Pages
     * @return array
     */
    protected function allPages()
    {
        return Page::listInTheme(Theme::getActiveTheme(), true)
            ->mapWithKeys(fn ($page) => [$page->baseFileName => $page->title])
            ->toArray();
    }
}
