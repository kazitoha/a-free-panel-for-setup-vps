<?php

namespace App\Http\Controllers;

use App\Models\Install;
use App\Services\InstallRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class InstallController extends Controller
{
    public function __construct(private readonly InstallRunner $runner) {}

    public function show(Install $install): View
    {
        return view('installs.show', [
            'install' => $install,
        ]);
    }

    /**
     * Polled by the install page: current status plus the log tail.
     */
    public function log(Install $install): JsonResponse
    {
        $status = $install->displayStatus();

        $payload = [
            'status' => $status,
            'message' => $install->message,
            'content' => $this->runner->readLog($install),
            'finished' => in_array($status, [Install::STATUS_SUCCESS, Install::STATUS_FAILED, 'stalled'], true),
        ];

        if ($status === 'stalled' && $payload['message'] === null) {
            $payload['message'] = 'The installer did not respond — check sudo setup and logs.';
        }

        return response()->json($payload);
    }
}
