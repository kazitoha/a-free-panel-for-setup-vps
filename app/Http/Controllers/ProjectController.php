<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidProject;
use App\Models\Database;
use App\Models\Install;
use App\Models\Project;
use App\Services\InstallRunner;
use App\Services\SudoRunner;
use App\Services\SystemService;
use App\Support\ProjectSpec;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class ProjectController extends Controller
{
    public function __construct(
        private readonly InstallRunner $runner,
        private readonly SudoRunner $sudo,
        private readonly SystemService $system,
    ) {}

    public function index(): View
    {
        $active = Install::ofType('project')
            ->whereIn('status', [Install::STATUS_PENDING, Install::STATUS_RUNNING])
            ->get()
            ->keyBy(fn (Install $install) => preg_replace('/\s+--dns$/', '', $install->command));

        $rows = Project::with('database')->orderBy('name')->get()->map(function (Project $project) use ($active) {
            $install = $active->get($this->commandFor($project));

            return [
                'project' => $project,
                'install' => $install,
                'status' => $install !== null
                    ? 'deploying'
                    : ($project->last_deploy_status ?? 'never'),
            ];
        });

        return view('projects.index', ['rows' => $rows]);
    }

    public function create(): View
    {
        $versions = $this->system->phpVersions();
        $default = (string) config('panel.php');

        if ($default !== '' && ! in_array($default, $versions, true)) {
            $versions[] = $default;
            sort($versions);
        }

        return view('projects.create', [
            'phpVersions' => $versions,
            'defaultPhp' => $default,
            'databases' => Database::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'database_id' => ['nullable', 'integer', 'exists:databases,id'],
        ]);

        try {
            $spec = ProjectSpec::validate($request->only('name', 'repo_url', 'branch', 'domain', 'php_version'));
        } catch (InvalidProject $exception) {
            return back()->withErrors($exception->errors)->withInput();
        }

        if (Project::where('name', $spec['name'])->exists()) {
            return back()->withErrors(['name' => 'A project with that name already exists.'])->withInput();
        }

        if (Project::where('domain', $spec['domain'])->exists()) {
            return back()->withErrors(['domain' => 'A project with that domain already exists.'])->withInput();
        }

        if (is_dir($spec['path']) && array_diff(scandir($spec['path']) ?: [], ['.', '..']) !== []) {
            return back()->withErrors(['name' => "Directory {$spec['path']} already exists on the server."])->withInput();
        }

        $project = Project::create($spec + [
            'database_id' => $request->filled('database_id') ? (int) $request->input('database_id') : null,
        ]);

        return $this->spawn($project, (bool) $request->boolean('dns'));
    }

    public function deploy(Project $project): RedirectResponse
    {
        $existing = Install::ofType('project')
            ->whereIn('command', $this->commandVariants($project))
            ->whereIn('status', [Install::STATUS_PENDING, Install::STATUS_RUNNING])
            ->first();

        if ($existing !== null) {
            return redirect()->route('installs.show', $existing);
        }

        return $this->spawn($project, false);
    }

    public function destroy(Request $request, Project $project): RedirectResponse
    {
        $arguments = ['panel:project:destroy', (string) $project->id];

        if ($request->boolean('purge')) {
            $arguments[] = '--purge';
        }

        $result = $this->sudo->run($arguments, 300.0);

        if ($result['exit'] !== 0) {
            if ($result['denied']) {
                return back()->with('error', SudoRunner::denialMessage());
            }

            $detail = trim($result['stderr'].$result['stdout']);
            $detail = $detail !== '' ? mb_substr($detail, 0, 300) : "The command exited with code {$result['exit']}.";

            return back()->with('error', "Could not remove the project: {$detail}");
        }

        $name = $project->name;
        $purged = $request->boolean('purge');
        $project->delete();

        return back()->with(
            'success',
            $purged
                ? "Project {$name} and its files removed."
                : "Project {$name} removed (files kept on the server).",
        );
    }

    /**
     * Create the install record and spawn the background deploy.
     */
    private function spawn(Project $project, bool $dns): RedirectResponse
    {
        $existing = Install::ofType('project')
            ->whereIn('command', $this->commandVariants($project))
            ->whereIn('status', [Install::STATUS_PENDING, Install::STATUS_RUNNING])
            ->latest('id')
            ->first();

        if ($existing !== null) {
            return redirect()->route('installs.show', $existing);
        }

        $install = Install::create([
            'type' => 'project',
            'label' => "Deploy {$project->name}",
            'command' => $this->commandFor($project).($dns ? ' --dns' : ''),
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

            $message = $exception->getMessage();

            if (str_contains($message, 'sudo')) {
                $message = SudoRunner::denialMessage();
            }

            return back()->with('error', $message);
        }

        return redirect()->route('installs.show', $install);
    }

    private function commandFor(Project $project): string
    {
        return 'panel:project:deploy '.$project->id;
    }

    /**
     * Stored command variants for a project (with and without the --dns flag).
     *
     * @return list<string>
     */
    private function commandVariants(Project $project): array
    {
        $base = $this->commandFor($project);

        return [$base, $base.' --dns'];
    }
}
