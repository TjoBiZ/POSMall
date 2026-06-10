<?php

declare(strict_types=1);

namespace KodZero\POSMall\Models;

use Model;

class ReviewSettings extends Model
{
    public $implement = ['System.Behaviors.SettingsModel'];

    public $settingsCode = 'kodzero_posmall_settings';

    public $settingsFields = '$/kodzero/posmall/models/settings/fields_reviews.yaml';

    public function initSettingsData()
    {
        $this->enabled         = true;
        $this->moderated       = false;
        $this->allow_anonymous = false;
    }
}
