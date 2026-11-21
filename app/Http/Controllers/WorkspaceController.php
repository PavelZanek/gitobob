<?php

namespace App\Http\Controllers;

use App\Git\RepositoryScanner;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Native\Desktop\Dialog;

final class WorkspaceController
{
    public function index(RepositoryScanner $scanner): View
    {
        $path = Storage::disk('local')->get('workspace.path');
        $path = is_string($path) ? $path : null;
        $available = $path !== null && is_dir($path) && is_readable($path);

        return view('workspace', [
            'path' => $path,
            'available' => $available,
            'tree' => $available ? $scanner->scan($path) : null,
        ]);
    }

    public function choose(): RedirectResponse
    {
        $path = Dialog::new()->folders()->title('Vybrat pracovní složku')->button('Vybrat složku')->open();
        if (is_string($path) && is_dir($path)) {
            Storage::disk('local')->put('workspace.path', $path);
        }

        return to_route('workspace');
    }
}
