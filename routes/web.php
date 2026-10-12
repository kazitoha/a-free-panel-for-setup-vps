<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\CaddySiteController;
use App\Http\Controllers\CronController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DatabaseController;
use App\Http\Controllers\DnsController;
use App\Http\Controllers\DnsRecordController;
use App\Http\Controllers\FirstRunController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\LogsController;
use App\Http\Controllers\MailboxController;
use App\Http\Controllers\MailController;
use App\Http\Controllers\PhpController;
use App\Http\Controllers\PmaController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\UpdateController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])
        ->middleware('setup.complete')
        ->name('login');
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('login.attempt');
    Route::get('/login/google', [AuthController::class, 'redirectToGoogle'])
        ->middleware('throttle:10,1')
        ->name('auth.google');
    Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback'])
        ->middleware('throttle:10,1')
        ->name('auth.google.callback');
});

// Credentials the installer could not set itself (a MySQL root password it
// was never given, or the administrator account) are collected here, before
// anything else, until no step is pending any more.
Route::middleware('setup.pending')->group(function () {
    Route::get('/first-run', [FirstRunController::class, 'show'])->name('firstrun.index');
    Route::post('/first-run', [FirstRunController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('firstrun.store');
});

Route::middleware(['auth', 'admin', 'setup.complete'])->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/setup', SetupController::class)->name('setup.index');
    Route::post('/services/restart', [ServiceController::class, 'restart'])
        ->middleware('throttle:10,1')
        ->name('services.restart');

    Route::prefix('sites')->name('caddy.')->group(function () {
        Route::get('/', [CaddySiteController::class, 'index'])->name('index');
        Route::get('/create', [CaddySiteController::class, 'create'])->name('create');
        Route::post('/', [CaddySiteController::class, 'store'])->name('store');
        Route::get('/{site}/edit', [CaddySiteController::class, 'edit'])
            ->where('site', '[a-z0-9.-]+')
            ->name('edit');
        Route::put('/{site}', [CaddySiteController::class, 'update'])
            ->where('site', '[a-z0-9.-]+')
            ->name('update');
        Route::delete('/{site}', [CaddySiteController::class, 'destroy'])
            ->where('site', '[a-z0-9.-]+')
            ->name('destroy');
    });

    Route::prefix('dns')->name('dns.')->group(function () {
        Route::get('/', [DnsController::class, 'index'])->name('index');
        Route::get('/create', [DnsController::class, 'create'])->name('create');
        Route::post('/', [DnsController::class, 'store'])->name('store');
        Route::post('/import', [DnsController::class, 'import'])
            ->middleware('throttle:3,1')
            ->name('import');
        Route::get('/{zone}', [DnsController::class, 'show'])
            ->where('zone', '[a-z0-9.-]+')
            ->name('show');
        Route::delete('/{zone}', [DnsController::class, 'destroy'])
            ->where('zone', '[a-z0-9.-]+')
            ->name('destroy');

        Route::post('/{zone}/records', [DnsRecordController::class, 'store'])
            ->where('zone', '[a-z0-9.-]+')
            ->name('records.store');
        Route::put('/{zone}/records', [DnsRecordController::class, 'update'])
            ->where('zone', '[a-z0-9.-]+')
            ->name('records.update');
        Route::delete('/{zone}/records', [DnsRecordController::class, 'destroy'])
            ->where('zone', '[a-z0-9.-]+')
            ->name('records.destroy');
    });

    Route::prefix('mail')->name('mail.')->group(function () {
        Route::get('/', [MailController::class, 'index'])->name('index');
        Route::post('/install', [MailController::class, 'install'])
            ->middleware('throttle:5,1')
            ->name('install');
        Route::get('/create', [MailController::class, 'create'])->name('create');
        Route::post('/', [MailController::class, 'store'])->name('store');
        Route::get('/{domain}', [MailController::class, 'show'])
            ->where('domain', '[0-9]+')
            ->name('show');
        Route::delete('/{domain}', [MailController::class, 'destroy'])
            ->where('domain', '[0-9]+')
            ->name('destroy');

        Route::post('/{domain}/mailboxes', [MailboxController::class, 'store'])
            ->where('domain', '[0-9]+')
            ->name('mailboxes.store');
        Route::put('/{domain}/mailboxes/{mailbox}', [MailboxController::class, 'update'])
            ->where(['domain' => '[0-9]+', 'mailbox' => '[0-9]+'])
            ->name('mailboxes.update');
        Route::delete('/{domain}/mailboxes/{mailbox}', [MailboxController::class, 'destroy'])
            ->where(['domain' => '[0-9]+', 'mailbox' => '[0-9]+'])
            ->name('mailboxes.destroy');
    });

    Route::prefix('databases')->name('databases.')->group(function () {
        Route::get('/', [DatabaseController::class, 'index'])->name('index');
        Route::post('/', [DatabaseController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('store');
        Route::get('/{database}/credentials', [DatabaseController::class, 'credentials'])
            ->name('credentials');
        Route::post('/{database}/password', [DatabaseController::class, 'password'])
            ->name('password');
        Route::delete('/{database}', [DatabaseController::class, 'destroy'])
            ->name('destroy');
    });

    Route::prefix('logs')->name('logs.')->group(function () {
        Route::get('/', [LogsController::class, 'index'])->name('index');
        Route::post('/caddyfile', [LogsController::class, 'saveCaddyfile'])
            ->middleware('throttle:10,1')
            ->name('caddyfile');
    });

    Route::prefix('projects')->name('projects.')->group(function () {
        Route::get('/', [ProjectController::class, 'index'])->name('index');
        Route::get('/create', [ProjectController::class, 'create'])->name('create');
        Route::post('/', [ProjectController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('store');
        Route::post('/{project}/deploy', [ProjectController::class, 'deploy'])
            ->whereNumber('project')
            ->middleware('throttle:10,1')
            ->name('deploy');
        Route::delete('/{project}', [ProjectController::class, 'destroy'])
            ->whereNumber('project')
            ->middleware('throttle:10,1')
            ->name('destroy');
    });

    Route::prefix('backups')->name('backups.')->group(function () {
        Route::get('/', [BackupController::class, 'index'])->name('index');
        Route::post('/', [BackupController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('store');
        Route::post('/{schedule}/run', [BackupController::class, 'run'])
            ->whereNumber('schedule')
            ->middleware('throttle:5,1')
            ->name('run');
        Route::post('/{schedule}/toggle', [BackupController::class, 'toggle'])
            ->whereNumber('schedule')
            ->name('toggle');
        Route::delete('/{schedule}', [BackupController::class, 'destroy'])
            ->whereNumber('schedule')
            ->name('destroy');
    });

    Route::prefix('cron')->name('cron.')->group(function () {
        Route::get('/', [CronController::class, 'index'])->name('index');
        Route::post('/', [CronController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('store');
        Route::post('/{job}/toggle', [CronController::class, 'toggle'])
            ->whereNumber('job')
            ->name('toggle');
        Route::post('/{job}/run', [CronController::class, 'run'])
            ->whereNumber('job')
            ->middleware('throttle:5,1')
            ->name('run');
        Route::delete('/{job}', [CronController::class, 'destroy'])
            ->whereNumber('job')
            ->name('destroy');
    });

    Route::post('/update/check', [UpdateController::class, 'check'])
        ->middleware('throttle:5,1')
        ->name('update.check');
    Route::post('/update/run', [UpdateController::class, 'run'])
        ->middleware('throttle:2,1')
        ->name('update.run');

    Route::get('/phpmyadmin', [PmaController::class, 'index'])->name('pma.index');
    Route::post('/phpmyadmin/install', [PmaController::class, 'install'])
        ->middleware('throttle:5,1')
        ->name('pma.install');
    Route::delete('/phpmyadmin', [PmaController::class, 'uninstall'])
        ->middleware('throttle:5,1')
        ->name('pma.uninstall');

    Route::get('/php', [PhpController::class, 'index'])->name('php.index');
    Route::post('/php/install', [PhpController::class, 'install'])
        ->middleware('throttle:5,1')
        ->name('php.install');
    Route::delete('/php', [PhpController::class, 'remove'])
        ->middleware('throttle:5,1')
        ->name('php.remove');

    Route::get('/installs/{install}', [InstallController::class, 'show'])
        ->name('installs.show');
    Route::get('/installs/{install}/log', [InstallController::class, 'log'])
        ->name('installs.log');

    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});
