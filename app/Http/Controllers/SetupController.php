<?php

namespace App\Http\Controllers;

use App\Services\SetupService;
use Illuminate\View\View;

class SetupController extends Controller
{
    public function __invoke(SetupService $setup): View
    {
        return view('setup', ['setup' => $setup->summary()]);
    }
}
