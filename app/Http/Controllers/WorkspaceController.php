<?php

namespace App\Http\Controllers;

use App\Git\RepositoryScanner;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Native\Desktop\Dialog;

final class WorkspaceController
{
    public function index(Request $request, RepositoryScanner $scanner): View
    {
        $path = Storage::disk('local')->get('workspace.path');
        $path = is_string($path) ? $path : null;
        $available = $path !== null && is_dir($path) && is_readable($path);

        $token = $request->query('batch');
        abort_unless($token === null || is_string($token), 400);
        $scan = $available ? $scanner->browse($path, $request->boolean('snapshot'), $request->boolean('opening') && $token === null, $token) : null;
        $tree = $scan['tree'] ?? null;
        $onlyChanged = $request->boolean('changed');

        return view('workspace', [
            'path' => $path,
            'available' => $available,
            'token' => $scan['token'] ?? null,
            'count' => $scan['count'] ?? 0,
            'hasRepositories' => $tree !== null,
            'onlyChanged' => $onlyChanged,
            'tree' => $onlyChanged ? $tree?->onlyChanged() : $tree,
        ]);
    }

    public function choose(): RedirectResponse
    {
        $path = Dialog::new()->folders()->title('Choose a workspace folder')->button('Choose folder')->open();
        if (is_string($path) && is_dir($path)) {
            Storage::disk('local')->put('workspace.path', $path);

            return to_route('workspace', ['opening' => 1]);
        }

        return to_route('workspace', ['snapshot' => 1]);
    }
}
