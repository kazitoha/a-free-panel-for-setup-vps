<?php

namespace App\Http\Controllers;

use App\Services\SystemService;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(SystemService $system): View
    {
        return view('dashboard', [
            'system' => $system->snapshot(),
        ]);
    }
}
