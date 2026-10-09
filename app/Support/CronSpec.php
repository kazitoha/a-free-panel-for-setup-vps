<?php

namespace App\Support;

use App\Exceptions\InvalidCron;

/**
 * Validation for panel-managed cron jobs: the five schedule fields and the
 * command itself. Only three command forms are accepted — the panel runner,
 * systemctl start/stop/restart/reload, and reboot — so a crafted job can
 * never inject shell syntax into /etc/cron.d/panel.
 */
class CronSpec
{
    /**
     * Frequency choices offered by the UI (label => key is inverted on purpose
     * for the select options; see frequencyOptions()).
     *
     * @var list<string>
     */
    public const array FREQUENCIES = ['hourly', 'every_hours', 'daily', 'weekly'];

    /**
     * Validate a cron job command and return it trimmed.
     *
     * @throws InvalidCron
     */
    public static function validateCommand(string $raw): string
    {
        $command = trim($raw);

        if ($command === '') {
            throw new InvalidCron(['command' => 'A command is required.']);
        }

        if (preg_match('/[\n\r]/', $command) === 1) {
            throw new InvalidCron(['command' => 'The command must be a single line.']);
        }

        $allowed = [
            // /usr/local/panel/run panel:xxx [args…] — same arg charset as the runner.
            '/\A\/usr\/local\/panel\/run panel:[a-z0-9:-]+(?: [A-Za-z0-9._@+=:\/-]+)*\z/',
            // systemctl with an explicit action — no other verbs, no extra args.
            '/\Asystemctl (?:start|stop|restart|reload) [A-Za-z0-9_.@:-]+\z/',
            // Plain reboot.
            '/\Areboot\z/',
        ];

        foreach ($allowed as $pattern) {
            if (preg_match($pattern, $command) === 1) {
                return $command;
            }
        }

        throw new InvalidCron(['command' => 'Only /usr/local/panel/run panel:* commands, "systemctl <start|stop|restart|reload> <unit>" and "reboot" are allowed.']);
    }

    /**
     * Validate the five cron schedule fields.
     *
     * @param  array{0: string, 1: string, 2: string, 3: string, 4: string}  $fields
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
     *
     * @throws InvalidCron
     */
    public static function validateFields(array $fields): array
    {
        $names = ['minute', 'hour', 'day of month', 'month', 'day of week'];

        foreach (array_values($fields) as $index => $field) {
            $field = (string) $field;

            if ($field === '' || strlen($field) > 20 || preg_match('/\A[\d*,\/-]+\z/', $field) !== 1) {
                throw new InvalidCron(['schedule' => "Invalid {$names[$index]} field: {$field}"]);
            }
        }

        return array_map('strval', array_values($fields));
    }

    /**
     * Build cron schedule fields from UI inputs: frequency, time (HH:MM),
     * optional every-hours interval and optional weekday (0-6, 0 = Sunday).
     *
     * @return array{cron_minute: string, cron_hour: string, cron_dom: string, cron_month: string, cron_dow: string}
     *
     * @throws InvalidCron
     */
    public static function fieldsFromRequest(string $frequency, string $time, ?string $everyHours = null, ?string $weekday = null): array
    {
        if (! in_array($frequency, self::FREQUENCIES, true)) {
            throw new InvalidCron(['frequency' => 'Choose a valid frequency.']);
        }

        if (preg_match('/\A([01]?\d|2[0-3]):([0-5]\d)\z/', trim($time), $matches) !== 1) {
            throw new InvalidCron(['time' => 'Time must be in HH:MM format (00:00 - 23:59).']);
        }

        $hour = (string) ((int) $matches[1]);
        $minute = (string) ((int) $matches[2]);

        return match ($frequency) {
            'hourly' => [
                'cron_minute' => $minute,
                'cron_hour' => '*',
                'cron_dom' => '*',
                'cron_month' => '*',
                'cron_dow' => '*',
            ],
            'every_hours' => self::everyHoursFields($minute, $everyHours),
            'daily' => [
                'cron_minute' => $minute,
                'cron_hour' => $hour,
                'cron_dom' => '*',
                'cron_month' => '*',
                'cron_dow' => '*',
            ],
            'weekly' => self::weeklyFields($minute, $hour, $weekday),
        };
    }

    /**
     * Frequency choices for the UI select: value => label.
     *
     * @return array<string, string>
     */
    public static function frequencyOptions(): array
    {
        return [
            'hourly' => 'Every hour',
            'every_hours' => 'Every N hours',
            'daily' => 'Daily',
            'weekly' => 'Weekly',
        ];
    }

    /**
     * Weekday choices for the UI select: value => label (0 = Sunday).
     *
     * @return array<string, string>
     */
    public static function weekdayOptions(): array
    {
        return [
            '0' => 'Sunday',
            '1' => 'Monday',
            '2' => 'Tuesday',
            '3' => 'Wednesday',
            '4' => 'Thursday',
            '5' => 'Friday',
            '6' => 'Saturday',
        ];
    }

    /**
     * @return array{cron_minute: string, cron_hour: string, cron_dom: string, cron_month: string, cron_dow: string}
     *
     * @throws InvalidCron
     */
    private static function everyHoursFields(string $minute, ?string $everyHours): array
    {
        $n = filter_var($everyHours, FILTER_VALIDATE_INT);

        if ($n === false || $n < 1 || $n > 23) {
            throw new InvalidCron(['every_hours' => 'Every-hours interval must be between 1 and 23.']);
        }

        return [
            'cron_minute' => $minute,
            'cron_hour' => "*/{$n}",
            'cron_dom' => '*',
            'cron_month' => '*',
            'cron_dow' => '*',
        ];
    }

    /**
     * @return array{cron_minute: string, cron_hour: string, cron_dom: string, cron_month: string, cron_dow: string}
     *
     * @throws InvalidCron
     */
    private static function weeklyFields(string $minute, string $hour, ?string $weekday): array
    {
        $day = filter_var($weekday, FILTER_VALIDATE_INT);

        if ($day === false || $day < 0 || $day > 6) {
            throw new InvalidCron(['weekday' => 'Choose a weekday (0 = Sunday).']);
        }

        return [
            'cron_minute' => $minute,
            'cron_hour' => $hour,
            'cron_dom' => '*',
            'cron_month' => '*',
            'cron_dow' => (string) $day,
        ];
    }
}
