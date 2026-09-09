<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

class ThemeController extends Controller
{
    public function index(): View
    {
        return view('ops.themes.index', [
            'org' => config('ops.themes.org'),
            'prefix' => config('ops.themes.repo_prefix'),
        ]);
    }
}
