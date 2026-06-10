<?php

declare(strict_types=1);

namespace KodZero\POSMall\Tests\Classes\System;

use KodZero\POSMall\Classes\System\SchedulerCronStatus;
use KodZero\POSMall\Tests\PluginTestCase;

class SchedulerCronStatusTest extends PluginTestCase
{
    public function test_recommended_tax_update_time_is_stable_for_one_installation_seed(): void
    {
        $seed = '/srv/customer-shop|base64:example-install-key';

        $this->assertSame(
            SchedulerCronStatus::recommendedDailyTimeForSeed($seed),
            SchedulerCronStatus::recommendedDailyTimeForSeed($seed)
        );
    }

    public function test_recommended_tax_update_time_spreads_most_installations_across_night_hours(): void
    {
        $night = 0;
        $total = 1000;
        $seenTimes = [];

        for ($index = 0; $index < $total; $index++) {
            $time = SchedulerCronStatus::recommendedDailyTimeForSeed(
                sprintf('/srv/posmall-shop-%04d|base64:test-key-%04d', $index, $index)
            );

            $this->assertGreaterThanOrEqual(0, $time['hour']);
            $this->assertLessThanOrEqual(23, $time['hour']);
            $this->assertGreaterThanOrEqual(1, $time['minute']);
            $this->assertLessThanOrEqual(59, $time['minute']);

            if ($time['hour'] >= 0 && $time['hour'] <= 5) {
                $night++;
            }

            $seenTimes[sprintf('%02d:%02d', $time['hour'], $time['minute'])] = true;
        }

        $nightRatio = $night / $total;

        $this->assertGreaterThanOrEqual(0.75, $nightRatio);
        $this->assertLessThanOrEqual(0.85, $nightRatio);
        $this->assertGreaterThan(250, count($seenTimes));
    }
}
