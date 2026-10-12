<?php

namespace App\Support;

/**
 * Reads the bootstrap installer's status files, written by install-panel.sh
 * and its scripts/install/*.sh steps:
 *
 *   <status_dir>/manifest.json     the ordered list of steps
 *   <status_dir>/steps/<key>.json  one result per step (pass/fail + reason)
 *
 * The Setup page uses it to name the step that failed and why. It is entirely
 * optional: without a manifest (a development install, or a panel set up
 * before this feature) every helper returns empty and the page is unchanged.
 */
class InstallStatus
{
    public static function dir(): string
    {
        $dir = config('panel.install.status_dir');

        return is_string($dir) ? rtrim($dir, '/') : '';
    }

    public static function available(): bool
    {
        $dir = self::dir();

        return $dir !== '' && is_file($dir.'/manifest.json');
    }

    /**
     * Every step in install order, merged with its result when it has run.
     *
     * @return list<array{key: string, label: string, status: string, message: string|null}>
     */
    public static function steps(): array
    {
        $dir = self::dir();
        $manifest = self::readJson($dir.'/manifest.json');
        $entries = $manifest['steps'] ?? null;

        if (! is_array($entries)) {
            return [];
        }

        $steps = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $key = (string) ($entry['key'] ?? '');

            if ($key === '') {
                continue;
            }

            $result = self::readJson($dir.'/steps/'.$key.'.json') ?? [];
            $message = $result['message'] ?? null;

            $steps[] = [
                'key' => $key,
                'label' => (string) ($result['label'] ?? $entry['label'] ?? $key),
                'status' => (string) ($result['status'] ?? 'pending'),
                'message' => is_string($message) && $message !== '' ? $message : null,
            ];
        }

        return $steps;
    }

    /**
     * The steps that did not finish successfully — what the Setup page needs.
     * Failures come first; a fully successful install returns nothing.
     *
     * @return list<array{key: string, label: string, status: string, message: string|null}>
     */
    public static function problems(): array
    {
        $problems = array_values(array_filter(
            self::steps(),
            fn (array $step): bool => $step['status'] !== 'success',
        ));

        usort(
            $problems,
            fn (array $a, array $b): int => ($b['status'] === 'failed') <=> ($a['status'] === 'failed'),
        );

        return $problems;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function readJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}
