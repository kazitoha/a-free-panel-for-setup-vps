<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\File;

/**
 * What still has to be filled in before the panel is usable.
 *
 * install-panel.sh leaves a marker behind when it could not create the
 * administrator account itself (it was not asked to, or was interrupted);
 * that account is collected on /first-run instead. While a step is pending
 * the panel serves that page rather than the login form; nothing else can
 * start a setup run.
 *
 * Everything the installer cannot generate on its own is handled elsewhere:
 * MySQL, PowerDNS and the mail server install themselves from their modules,
 * each one creating whatever credentials it needs.
 */
class SetupState
{
    /** Administrator account still has to be created. */
    public const STEP_ADMIN = 'admin';

    /**
     * What the installer may record in the marker: the administrator account
     * it was not asked to create. Dropped again as soon as it exists — an
     * existing administrator cancels the step, so the marker can never offer
     * to mint a second one.
     */
    private const KNOWN_STEPS = [self::STEP_ADMIN];

    /**
     * Steps that are still outstanding, in the order they are collected.
     *
     * @return list<string>
     */
    public function pending(): array
    {
        $steps = $this->marked();

        if (in_array(self::STEP_ADMIN, $steps, true) && $this->hasAdministrator()) {
            $steps = array_values(array_diff($steps, [self::STEP_ADMIN]));
        }

        return $steps;
    }

    /**
     * Whether /first-run should be served (and every other page redirected).
     */
    public function isPending(): bool
    {
        return $this->pending() !== [];
    }

    /**
     * Whether any administrator account exists yet.
     */
    public function hasAdministrator(): bool
    {
        return User::query()->where('is_admin', true)->exists();
    }

    /**
     * Record the steps that still need doing (used by the installer's
     * equivalent write and by the tests).
     *
     * @param  list<string>  $steps
     */
    public function mark(array $steps): void
    {
        $steps = array_values(array_unique(array_intersect($steps, self::KNOWN_STEPS)));

        if ($steps === []) {
            $this->complete();

            return;
        }

        File::ensureDirectoryExists(dirname($this->path()));
        File::put($this->path(), json_encode([
            'steps' => $steps,
            'created_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    /**
     * Everything was collected: drop the marker so the login page returns.
     */
    public function complete(): void
    {
        File::delete($this->path());
    }

    /**
     * Steps the installer recorded. A marker that cannot be read still opens
     * the wizard for the administrator account — the only step left that a
     * page has to collect.
     *
     * @return list<string>
     */
    private function marked(): array
    {
        $path = $this->path();

        if ($path === '' || ! is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);

        if ($raw === false) {
            return [self::STEP_ADMIN];
        }

        $data = json_decode($raw, true);

        if (! is_array($data) || ! isset($data['steps']) || ! is_array($data['steps'])) {
            return [self::STEP_ADMIN];
        }

        $steps = array_values(array_unique(array_intersect($data['steps'], self::KNOWN_STEPS)));

        return $steps === [] ? [self::STEP_ADMIN] : $steps;
    }

    private function path(): string
    {
        $path = config('panel.setup.marker');

        return is_string($path) ? $path : '';
    }
}
