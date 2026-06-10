<?php

declare(strict_types=1);

namespace KodZero\POSMall\Models;

use Model;

class FeedSettings extends Model
{
    public $implement = ['System.Behaviors.SettingsModel'];

    public $settingsCode = 'kodzero_posmall_settings';

    public $settingsFields = '$/kodzero/posmall/models/settings/fields_feeds.yaml';

    public function filterFields()
    {
        if (FeedSettings::get('google_merchant_key') === null) {
            FeedSettings::set('google_merchant_key', str_random(12));
        }
    }
}
