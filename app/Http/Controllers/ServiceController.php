<?php

namespace App\Http\Controllers;

use App\Services\SudoRunner;
use App\Support\ServiceUnits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function restart(Request $request, SudoRunner $sudo): RedirectResponse
    {
        $validated = $request->validate([
            'unit' => [
                'required',
                'string',
                'max:64',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! ServiceUnits::isValid((string) $value)) {
                        $fail('That service is not managed by this panel.');
                    }
                },
            ],
        ]);

        $unit = $validated['unit'];
        $result = $sudo->run(['panel:service:restart', $unit]);

        if ($result['exit'] === 0) {
            return back()->with('success', "Restarted {$unit}.");
        }

        if ($result['denied']) {
            return back()->with('error', SudoRunner::denialMessage());
        }

        $detail = trim($result['stderr'].$result['stdout']);
        $detail = preg_replace('/[ \t]*\n+[ \t]*/', ' · ', $detail) ?? '';
        $detail = $detail !== '' ? mb_substr($detail, 0, 1200) : "systemctl exited with code {$result['exit']}.";

        return back()->with('error', "Could not restart {$unit}: {$detail}");
    }
}
