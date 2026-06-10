<?php

namespace KodZero\POSMall\Classes\Jobs;

use DB;
use Illuminate\Contracts\Queue\Job;
use KodZero\POSMall\Models\PropertyValue;

class PropertyRemovalUpdate
{
    public function fire(Job $job, $data)
    {
        if ($job->attempts() > 5) {
            logger()->error('Failed to handle property removal. Please run php artisan posmall:index manually to update your index');
            $job->delete();
        }

        // Reset any products that were grouped by a removed property.
        DB::table('kodzero_posmall_products')
            ->whereIn('group_by_property_id', $data['properties'] ?? [])
            ->update([
                'group_by_property_id' => null,
            ]);

        PropertyValue::with(['product', 'variant'])
            ->orderBy('id')
            ->whereIn('property_id', $data['properties'] ?? [])
            ->where(function ($query) use ($data) {
                $query
                    ->whereIn('product_id', $data['products'] ?? [])
                    ->orWhereIn('variant_id', $data['variants'] ?? []);
            })
            ->chunk(100, function ($values) {
                // Tiggers a re-index via the PropertyValueObserver.
                $values->each->delete();
            });

        $job->delete();
    }
}
