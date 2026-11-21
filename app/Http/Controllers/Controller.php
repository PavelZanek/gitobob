<?php

namespace App\Http\Controllers;

use App\Git\RepositoryScanner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

abstract class Controller
{
    protected function directory(Request $request, RepositoryScanner $scanner): string
    {
        abort_unless(in_array($request->getHost(), ['localhost', '127.0.0.1', '[::1]'], true)
            && in_array($request->ip(), ['127.0.0.1', '::1'], true), 403);
        $root = Storage::disk('local')->get('workspace.path');
        $relative = $request->query('repo');
        abort_unless(is_string($root) && is_string($relative), 404);
        $path = $scanner->repositoryDirectory($root, $relative);
        abort_if($path === null, 404);

        return $path;
    }
}
