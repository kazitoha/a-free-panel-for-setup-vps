<?php

namespace App\Http\Controllers;

use App\Services\HealthService;
use App\Services\SystemService;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(SystemService $system, HealthService $health): View
    {
        return view('dashboard', [
            'system' => $system->snapshot(),
            'health' => $health->report(),
        ]);
    }
}
