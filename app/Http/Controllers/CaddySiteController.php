<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidSiteSpec;
use App\Services\CaddySites;
use App\Services\SudoRunner;
use App\Services\SystemService;
use App\Support\SiteSpec;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CaddySiteController extends Controller
{
    public function __construct(
        private readonly CaddySites $sites,
        private readonly SudoRunner $sudo,
        private readonly SystemService $system,
    ) {}

    public function index(): View
    {
        return view('caddy.index', [
            'sites' => $this->sites->list(),
        ]);
    }

    public function create(): View
    {
        return view('caddy.form', $this->formData([
            'domain' => '',
            'wildcard' => false,
            'type' => 'php',
            'php_version' => config('panel.php'),
            'docroot' => '',
            'target' => '',
        ]));
    }

    public function store(Request $request): RedirectResponse|View
    {
        $spec = $this->validateSpec($request);

        if ($spec instanceof RedirectResponse) {
            return $spec;
        }

        $name = SiteSpec::nameFor($spec['domain']);

        if ($this->sites->exists($name)) {
            return back()
                ->withErrors(['domain' => "A site named {$name} already exists."])
                ->withInput();
        }

        $error = $this->apply('panel:caddy:save', $name, $this->sites->writeSpec($name, $spec));

        if ($error !== null) {
            return back()->with('error', $error)->withInput();
        }

        return redirect()
            ->route('caddy.index')
            ->with('success', "Site {$spec['domain']} added — Caddy reloaded.");
    }

    public function edit(string $site): View|RedirectResponse
    {
        if (! SiteSpec::isValidName($site)) {
            abort(404);
        }

        if ($this->sites->isReserved($site)) {
            abort(403, "{$site} is managed by the panel.");
        }

        $content = $this->sites->read($site);

        if ($content === null) {
            abort(404);
        }

        // Prefer the saved form spec; fall back to parsing the site file.
        $spec = $this->sites->readSpec($site) ?? SiteSpec::parse($content) ?? [
            'domain' => $site,
            'wildcard' => false,
            'type' => 'static',
            'php_version' => config('panel.php'),
            'docroot' => "/var/www/{$site}",
            'target' => '',
        ];

        return view('caddy.form', $this->formData($spec + [
            'editing' => $site,
        ]));
    }

    public function update(Request $request, string $site): RedirectResponse|View
    {
        if (! SiteSpec::isValidName($site)) {
            abort(404);
        }

        if ($this->sites->isReserved($site)) {
            abort(403, "{$site} is managed by the panel.");
        }

        if (! $this->sites->exists($site)) {
            abort(404);
        }

        $spec = $this->validateSpec($request);

        if ($spec instanceof RedirectResponse) {
            return $spec;
        }

        $name = SiteSpec::nameFor($spec['domain']);

        if ($name !== $site && $this->sites->exists($name)) {
            return back()
                ->withErrors(['domain' => "A site named {$name} already exists."])
                ->withInput();
        }

        $error = $this->apply('panel:caddy:save', $name, $this->sites->writeSpec($name, $spec));

        if ($error !== null) {
            return back()->with('error', $error)->withInput();
        }

        if ($name !== $site) {
            // Domain renamed: drop the previous file (best effort — the new
            // site already serves, a leftover file would be a duplicate).
            $this->sudo->run(['panel:caddy:delete', $site], 60);
            $this->sites->deleteSpec($site);
        }

        return redirect()
            ->route('caddy.index')
            ->with('success', "Site {$spec['domain']} saved — Caddy reloaded.");
    }

    public function destroy(string $site): RedirectResponse
    {
        if (! SiteSpec::isValidName($site)) {
            abort(404);
        }

        if ($this->sites->isReserved($site)) {
            abort(403, "{$site} is managed by the panel.");
        }

        $error = $this->apply('panel:caddy:delete', $site, null);

        if ($error !== null) {
            return back()->with('error', $error);
        }

        $this->sites->deleteSpec($site);

        return back()->with('success', "Site {$site} removed — Caddy reloaded.");
    }

    /**
     * Run a root-side caddy command; returns an error message or null on success.
     */
    private function apply(string $command, string $name, ?string $specPath): ?string
    {
        $arguments = [$command, $name];

        if ($specPath !== null) {
            $arguments[] = '--spec='.$specPath;
        }

        $result = $this->sudo->run($arguments, 60);

        if ($result['exit'] === 0) {
            return null;
        }

        if ($result['denied']) {
            return SudoRunner::denialMessage();
        }

        $detail = trim($result['stdout'].$result['stderr']);
        $detail = $detail !== '' ? $detail : "The command exited with code {$result['exit']}.";

        return 'Could not apply the site: '.mb_substr($detail, 0, 400);
    }

    /**
     * Validate the request through SiteSpec (single source of truth).
     */
    private function validateSpec(Request $request): RedirectResponse|array
    {
        $request->validate([
            'domain' => ['required', 'string', 'max:253'],
            'type' => ['required', 'string', 'max:16'],
            'php_version' => ['nullable', 'string', 'max:8'],
            'docroot' => ['nullable', 'string', 'max:400'],
            'target' => ['nullable', 'string', 'max:253'],
            'wildcard' => ['nullable'],
        ]);

        try {
            return SiteSpec::validate($request->only([
                'domain', 'wildcard', 'type', 'php_version', 'docroot', 'target',
            ]));
        } catch (InvalidSiteSpec $exception) {
            return back()->withErrors($exception->errors)->withInput();
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function formData(array $spec): array
    {
        return [
            'spec' => $spec,
            'editing' => $spec['editing'] ?? null,
            'phpVersions' => $this->phpVersions(),
        ];
    }

    /**
     * @return list<string>
     */
    private function phpVersions(): array
    {
        $versions = $this->system->phpVersions();

        $default = (string) config('panel.php');

        if ($default !== '' && ! in_array($default, $versions, true)) {
            $versions[] = $default;
            sort($versions);
        }

        return $versions;
    }
}
