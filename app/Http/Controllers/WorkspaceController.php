<?php

namespace App\Http\Controllers;

use App\Git\BranchPush;
use App\Git\LocalBranches;
use App\Git\RepositoryScanner;
use App\Git\RepositoryStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Native\Desktop\Dialog;

final class WorkspaceController extends Controller
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

    public function catalog(Request $request, RepositoryScanner $scanner, LocalBranches $branches): JsonResponse
    {
        $root = Storage::disk('local')->get('workspace.path');
        $rows = [];
        if (is_string($root)) {
            foreach ($scanner->workspaceTree($root, true)?->repositories() ?? [] as $path => $status) {
                $relative = (string) $path;
                $directory = $scanner->repositoryDirectory($root, $relative);
                if ($directory !== null) {
                    $rows[] = ['repo' => $relative, 'name' => $relative === '.' ? basename($root) : $relative, 'current' => $status->branch,
                        'branches' => $branches->names($directory)];
                }
            }
        }

        return response()->json(['workspace' => hash('sha256', (string) $root), 'projects' => $rows])->header('Cache-Control', 'no-store');
    }

    public function fetchList(RepositoryScanner $scanner): View
    {
        $root = Storage::disk('local')->get('workspace.path');

        return view('workspace-fetch', ['repositories' => is_string($root) ? $scanner->workspaceTree($root, true)?->repositories() ?? [] : [],
            'workspace' => hash('sha256', (string) $root), 'name' => basename((string) $root)]);
    }

    public function fetchRepository(Request $request, RepositoryScanner $scanner, BranchPush $push): JsonResponse
    {
        $directory = $this->directory($request, $scanner);
        $data = $request->validate(['workspace' => ['required', 'string', 'size:64'], 'branch' => ['required', 'string'], 'upstream' => ['required', 'string']]);
        $root = Storage::disk('local')->get('workspace.path');
        abort_unless(is_string($root) && hash('sha256', $root) === $data['workspace'] && $scanner->repositoryDirectory($root, (string) $request->query('repo')) === $directory, 409);
        $notice = Cache::lock('index:'.hash('sha256', $directory), 120)->block(5,
            fn (): string => $push->fetchFromWorkspace($directory, $data['branch'], $data['upstream']));
        $ok = str_starts_with($notice, 'Fetched ');
        $status = $ok ? RepositoryStatus::read($directory, tracking: true) : null;
        $query = $request->only('repo');
        $url = match (true) {
            $status === null => route('repository.push.preview', $query),
            $status->ahead > 0 && $status->behind > 0 => route('repository.compare', $query + ['base' => $status->upstreamRef, 'head' => 'refs/heads/'.$status->branch]),
            $status->behind > 0, $status->ahead > 0 => route('repository.push.preview', $query),
            default => route('repository', $query),
        };

        return response()->json(['ok' => $ok, 'notice' => $notice, 'ahead' => $status?->ahead, 'behind' => $status?->behind, 'url' => $url]);
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
