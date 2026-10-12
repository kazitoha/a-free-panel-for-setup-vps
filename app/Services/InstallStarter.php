<?php

namespace App\Services;

use App\Models\Install;
use RuntimeException;

/**
 * Queues a long-running panel job (phpMyAdmin, MySQL, PowerDNS, mail…) in the
 * background and returns the install row the UI should watch. An install that
 * is already pending or running is returned as-is, so a double click never
 * starts a second copy.
 */
class InstallStarter
{
    public function __construct(private readonly InstallRunner $runner) {}

    /**
     * @throws RuntimeException when the job could not be spawned
     */
    public function start(string $type, string $label, string $command): Install
    {
        $active = Install::ofType($type)
            ->whereIn('status', [Install::STATUS_PENDING, Install::STATUS_RUNNING])
            ->latest('id')
            ->first();

        if ($active !== null) {
            return $active;
        }

        $install = Install::create([
            'type' => $type,
            'label' => $label,
            'command' => $command,
            'status' => Install::STATUS_PENDING,
        ]);

        try {
            $this->runner->start($install);
        } catch (RuntimeException $exception) {
            $install->forceFill([
                'status' => Install::STATUS_FAILED,
                'message' => mb_substr($exception->getMessage(), 0, 500),
                'finished_at' => now(),
            ])->save();

            throw $exception;
        }

        return $install;
    }

    /**
     * Message for the flash bag: a refused sudo call reads better as the
     * standing explanation than as the raw command output.
     */
    public static function failureMessage(\Throwable $exception): string
    {
        $message = $exception->getMessage();

        return str_contains($message, 'sudo') ? SudoRunner::denialMessage() : $message;
    }
}
