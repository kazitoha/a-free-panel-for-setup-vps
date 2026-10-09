<?php

namespace App\Http\Controllers;

use App\Services\SudoRunner;
use App\Support\ServiceUnits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LogsController extends Controller
{
    private const array SOURCES = ['journal', 'app', 'caddyfile'];

    public function __construct(private readonly SudoRunner $sudo) {}

    public function index(Request $request): View
    {
        $source = (string) $request->query('source');
        $source = in_array($source, self::SOURCES, true) ? $source : 'journal';

        $units = $this->units();

        $data = [
            'source' => $source,
            'units' => $units,
            'content' => null,
            'error' => null,
            'unit' => null,
            'lines' => null,
            'files' => [],
            'file' => null,
        ];

        return match ($source) {
            'journal' => view('logs.index', $this->journal($request, $units) + $data),
            'app' => view('logs.index', $this->appLogs($request) + $data),
            default => view('logs.index', $this->caddyfile() + $data),
        };
    }

    public function saveCaddyfile(Request $request): RedirectResponse
    {
        $content = (string) $request->input('content', '');

        if (strlen($content) > 200_000) {
            return back()->with('error', 'The Caddyfile is too large (max 200 KB).')->withInput();
        }

        if (str_contains($content, "\0")) {
            return back()->with('error', 'The Caddyfile contains binary data.')->withInput();
        }

        $staged = storage_path('app/caddyfile-editor-'.uniqid('', true));

        if (@file_put_contents($staged, $content) === false) {
            return back()->with('error', 'Could not stage the Caddyfile for saving.');
        }

        @chmod($staged, 0600);

        try {
            $result = $this->sudo->run(['panel:caddyfile:write', $staged], 60.0);
        } finally {
            @unlink($staged);
        }

        if ($result['exit'] !== 0) {
            if ($result['denied']) {
                return back()->with('error', SudoRunner::denialMessage())->withInput();
            }

            $detail = trim($result['stderr'].$result['stdout']);
            $detail = $detail !== '' ? mb_substr($detail, 0, 600) : "The command exited with code {$result['exit']}.";

            return back()->with('error', $detail)->withInput();
        }

        return redirect()->route('logs.index', ['source' => 'caddyfile'])
            ->with('success', 'Caddyfile saved and Caddy reloaded.');
    }

    /**
     * @param  list<array{unit: string, name: string}>  $units
     * @return array{unit: string, lines: int, content: ?string, error: ?string}
     */
    private function journal(Request $request, array $units): array
    {
        $unit = (string) $request->query('unit', $units[0]['unit'] ?? 'caddy');

        if (! ServiceUnits::isValid($unit)) {
            $unit = $units[0]['unit'] ?? 'caddy';
        }

        $lines = (int) $request->query('lines', 200);
        $lines = max(50, min(1000, $lines));

        $result = $this->sudo->run(['panel:logs:journal', $unit, '--lines='.$lines], 45.0);

        if ($result['exit'] !== 0) {
            $detail = trim($result['stderr'].$result['stdout']);

            return [
                'unit' => $unit,
                'lines' => $lines,
                'content' => null,
                'error' => $detail !== '' ? mb_substr($detail, 0, 500) : "The command exited with code {$result['exit']}.",
            ];
        }

        return [
            'unit' => $unit,
            'lines' => $lines,
            'content' => trim($result['stdout']) !== '' ? trim($result['stdout']) : null,
            'error' => null,
        ];
    }

    /**
     * @return array{files: list<array{name: string, size: int, modified: string}>, file: ?string, content: ?string, error: ?string}
     */
    private function appLogs(Request $request): array
    {
        $files = [];

        foreach (glob(storage_path('logs/*.log')) ?: [] as $path) {
            $files[] = [
                'name' => basename($path),
                'size' => (int) filesize($path),
                'modified' => date('Y-m-d H:i', (int) filemtime($path)),
            ];
        }

        usort($files, fn (array $a, array $b) => strcmp($b['modified'], $a['modified']));

        if ($files === []) {
            return ['files' => [], 'file' => null, 'content' => null, 'error' => null];
        }

        $requested = (string) $request->query('file', $files[0]['name']);
        $allowed = array_column($files, 'name');

        $file = in_array($requested, $allowed, true) ? $requested : $files[0]['name'];

        return [
            'files' => $files,
            'file' => $file,
            'content' => $this->tail(storage_path('logs/'.$file)),
            'error' => null,
        ];
    }

    /**
     * @return array{content: ?string, error: ?string}
     */
    private function caddyfile(): array
    {
        $result = $this->sudo->run(['panel:caddyfile:read'], 30.0);

        if ($result['exit'] !== 0) {
            $detail = trim($result['stderr'].$result['stdout']);

            if ($result['denied']) {
                $detail = SudoRunner::denialMessage();
            }

            return [
                'content' => null,
                'error' => $detail !== '' ? mb_substr($detail, 0, 500) : "The command exited with code {$result['exit']}.",
            ];
        }

        return ['content' => $result['stdout'], 'error' => null];
    }

    /**
     * @return list<array{unit: string, name: string}>
     */
    private function units(): array
    {
        $units = ServiceUnits::candidates();

        // The panel's own PHP-FPM is never part of the static candidates.
        $php = (string) config('panel.php');

        if ($php !== '' && ! in_array('php'.$php.'-fpm', array_column($units, 'unit'), true)) {
            $units[] = ['unit' => 'php'.$php.'-fpm', 'name' => "PHP {$php}-fpm"];
        }

        // stalwart / stalwart-mail are both offered; keep them distinct.
        return $units;
    }

    private function tail(string $path, int $maxBytes = 100_000): string
    {
        if (! is_file($path)) {
            return '';
        }

        $size = (int) filesize($path);

        if ($size <= $maxBytes) {
            return (string) file_get_contents($path);
        }

        $handle = fopen($path, 'rb');
        fseek($handle, $size - $maxBytes);

        return (string) fread($handle, $maxBytes);
    }
}
