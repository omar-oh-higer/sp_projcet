<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/** Serves the visual NFR demo dashboard at /demo (calls existing /api routes from the browser). */
class NfrDemoController extends Controller
{
    public function index(): View
    {
        return view('demo.index', [
            'tasks' => config('nfr_demo.tasks'),
            'products' => config('nfr_demo.products'),
        ]);
    }
}
