<?php

namespace Tests\Unit;

use App\Support\ServiceUnits;
use PHPUnit\Framework\TestCase;

class ServiceUnitsTest extends TestCase
{
    public function test_allowlisted_units_are_accepted(): void
    {
        foreach (['caddy', 'mysql', 'mariadb', 'pdns', 'stalwart', 'stalwart-mail', 'php8.3-fpm', 'php7.4-fpm', 'redis-server', 'ufw'] as $unit) {
            $this->assertTrue(ServiceUnits::isValid($unit), "{$unit} should be allowed");
        }
    }

    public function test_arbitrary_or_malicious_units_are_rejected(): void
    {
        foreach ([
            'ssh',
            'cron',
            'caddy; rm -rf /',
            'php-fpm',
            '../../../bin/sh',
            'php83-fpm',
            '',
            'CADDY',
        ] as $unit) {
            $this->assertFalse(ServiceUnits::isValid($unit), "{$unit} should be rejected");
        }
    }

    public function test_candidates_have_unique_units(): void
    {
        $units = array_column(ServiceUnits::candidates(), 'unit');

        $this->assertSame(array_unique($units), $units);
    }
}
