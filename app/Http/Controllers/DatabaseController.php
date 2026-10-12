<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidDatabase;
use App\Models\Database;
use App\Models\Install;
use App\Services\InstallStarter;
use App\Services\SudoRunner;
use App\Support\DatabaseSpec;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class DatabaseController extends Controller
{
    public function __construct(
        private readonly SudoRunner $sudo,
        private readonly InstallStarter $starter,
    ) {}

    public function index(): View
    {
        $live = $this->liveList();
        $liveByName = collect($live ?? [])->keyBy('name');

        $rows = Database::orderBy('name')->get()->map(function (Database $database) use ($live, $liveByName) {
            $liveRow = $liveByName->get($database->name);

            return [
                'id' => $database->id,
                'name' => $database->name,
                'username' => $database->username,
                'size' => $this->formatSize($liveRow['size_kb'] ?? null),
                'tables' => $liveRow !== null ? (int) $liveRow['tables'] : null,
                'present' => $live === null ? null : $liveRow !== null,
            ];
        });

        $latest = Install::ofType('mysql')->latest('id')->first();

        return view('databases.index', [
            'rows' => $rows,
            'listError' => $live === null
                ? 'Could not reach MySQL for live sizes — server details below are from the panel record.'
                : null,
            'needsInstall' => $live === null,
            'active' => $latest?->isActive() ? $latest : null,
            'latest' => $latest,
        ]);
    }

    /**
     * Install (or unlock) MySQL: the Databases module's own first step.
     */
    public function install(): RedirectResponse
    {
        try {
            $install = $this->starter->start('mysql', 'Install MySQL', 'panel:mysql:install');
        } catch (RuntimeException $exception) {
            return back()->with('error', InstallStarter::failureMessage($exception));
        }

        return redirect()->route('installs.show', $install);
    }

    public function store(Request $request): RedirectResponse
    {
        try {
            $spec = DatabaseSpec::validate($request->only('name', 'username', 'password'));
        } catch (InvalidDatabase $exception) {
            return back()->withErrors($exception->errors)->onlyInput('name', 'username');
        }

        if (Database::where('name', $spec['name'])->exists()) {
            return back()->withErrors(['name' => 'That database already exists in the panel.'])->onlyInput('name', 'username');
        }

        if (Database::where('username', $spec['username'])->exists()) {
            return back()->withErrors(['username' => 'That username is already used by another panel database.'])->onlyInput('name', 'username');
        }

        $result = $this->sudo->run([
            'panel:database:create',
            '--password-stdin',
            $spec['name'],
            $spec['username'],
        ], 60.0, $spec['password']."\n");

        if ($result['exit'] !== 0) {
            return back()->with('error', $this->failureMessage('create the database', $result))
                ->onlyInput('name', 'username');
        }

        Database::create($spec);

        return redirect()->route('databases.index')
            ->with('success', "Database {$spec['name']} created. Reveal the credentials with the show button.");
    }

    public function password(Request $request, Database $database): RedirectResponse
    {
        $password = (string) $request->input('password', '');
        $errors = DatabaseSpec::passwordErrors($password);

        if ($errors !== []) {
            return back()->withErrors($errors);
        }

        $result = $this->sudo->run([
            'panel:database:user-password',
            '--password-stdin',
            $database->username,
        ], 60.0, $password."\n");

        if ($result['exit'] !== 0) {
            return back()->with('error', $this->failureMessage('rotate the password', $result));
        }

        $database->update(['password' => $password]);

        return back()->with('success', "Password updated for {$database->username}.");
    }

    public function destroy(Database $database): RedirectResponse
    {
        $result = $this->sudo->run([
            'panel:database:drop',
            $database->name,
            $database->username,
        ], 60.0);

        if ($result['exit'] !== 0) {
            return back()->with('error', $this->failureMessage('drop the database', $result));
        }

        $name = $database->name;
        $database->delete();

        return back()->with('success', "Database {$name} dropped.");
    }

    /**
     * Reveal the stored credentials of a panel database.
     *
     * @return array{username: string, password: string}
     */
    public function credentials(Database $database): array
    {
        return [
            'username' => $database->username,
            'password' => $database->password,
        ];
    }

    /**
     * Ask the root runner for the live database list.
     *
     * @return list<array{name: string, size_kb: int, tables: int}>|null Null when MySQL/the runner is unavailable.
     */
    private function liveList(): ?array
    {
        $result = $this->sudo->run(['panel:database:list'], 60.0);

        if ($result['exit'] !== 0) {
            return null;
        }

        $lines = array_values(array_filter(explode("\n", trim($result['stdout']))));
        $last = $lines === [] ? '' : (string) end($lines);
        $decoded = json_decode($last, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array{exit: int, stdout: string, stderr: string, denied: bool}  $result
     */
    private function failureMessage(string $action, array $result): string
    {
        if ($result['denied']) {
            return SudoRunner::denialMessage();
        }

        $detail = trim($result['stderr'].$result['stdout']);

        $detail = $detail !== ''
            ? mb_substr($detail, 0, 300)
            : "The command exited with code {$result['exit']}.";

        return "Could not {$action}: {$detail}";
    }

    private function formatSize(?int $kb): string
    {
        if ($kb === null) {
            return '—';
        }

        if ($kb < 1024) {
            return "{$kb} KB";
        }

        if ($kb < 1024 * 1024) {
            return number_format($kb / 1024, 1).' MB';
        }

        return number_format($kb / 1024 / 1024, 2).' GB';
    }
}
