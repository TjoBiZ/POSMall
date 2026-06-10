<?php

namespace KodZero\POSMall\Classes\Jobs;

use Cache;
use Illuminate\Contracts\Queue\Job;
use KodZero\POSMall\Models\Category;
use KodZero\POSMall\Models\UniquePropertyValue;

class UpdateUniquePropertyForCategory
{
    public function fire(Job $job, $data)
    {
        $category = Category::find($data['id']);

        Cache::forget(UniquePropertyValue::getCacheKeyForCategory($category));

        UniquePropertyValue::resetForCategory($category);

        $job->delete();
    }
}
