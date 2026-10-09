<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CaddySiteController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DnsController;
use App\Http\Controllers\DnsRecordController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\MailboxController;
use App\Http\Controllers\MailController;
use App\Http\Controllers\PmaController;
use App\Http\Controllers\ServiceController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('login.attempt');
});

Route::middleware('auth')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
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

    Route::get('/phpmyadmin', [PmaController::class, 'index'])->name('pma.index');
    Route::post('/phpmyadmin/install', [PmaController::class, 'install'])
        ->middleware('throttle:5,1')
        ->name('pma.install');
    Route::delete('/phpmyadmin', [PmaController::class, 'uninstall'])
        ->middleware('throttle:5,1')
        ->name('pma.uninstall');

    Route::get('/installs/{install}', [InstallController::class, 'show'])
        ->name('installs.show');
    Route::get('/installs/{install}/log', [InstallController::class, 'log'])
        ->name('installs.log');

    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});
