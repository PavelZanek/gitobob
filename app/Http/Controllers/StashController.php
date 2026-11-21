<?php

namespace App\Http\Controllers;

use App\Git\ConflictReview;
use App\Git\RepositoryScanner;
use App\Git\RepositoryStatus;
use App\Git\Stashes;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class StashController extends Controller
{
    public function preview(Request $request, RepositoryScanner $scanner, Stashes $stashes): View
    {
        $directory = $this->directory($request, $scanner);
        $target = $request->string('target')->toString();
        $state = $stashes->workspace($directory, $target);
        abort_unless($state['validTarget'], 404);

        return view('stash-switch', $state + ['target' => $target, 'name' => basename($directory), 'relative' => $request->query('repo')]);
    }

    public function index(Request $request, RepositoryScanner $scanner, Stashes $stashes): View
    {
        $directory = $this->directory($request, $scanner);
        $entries = $stashes->entries($directory);
        $oid = $request->query('stash', array_key_first($entries));
        abort_unless($oid === null || (is_string($oid) && isset($entries[$oid])), 404);
        $detail = $oid === null ? null : $stashes->review($directory, $oid, $request->string('file')->toString());

        return view('stashes', $stashes->workspace($directory) + ['entries' => $entries, 'oid' => $oid, 'detail' => $detail,
            'name' => basename($directory), 'relative' => $request->query('repo')]);
    }

    public function change(Request $request, RepositoryScanner $scanner, Stashes $stashes, string $operation): RedirectResponse
    {
        $directory = $this->directory($request, $scanner);
        $data = $request->validate(['version' => ['required', 'string', 'size:64']] + ($operation === 'apply'
            ? ['stash' => ['required', 'string', 'max:64']]
            : ['target' => ['required', 'string', 'max:1024'], 'note' => ['nullable', 'string', 'max:512', 'not_regex:/\\x00/'], 'untracked' => ['sometimes', 'boolean']]));
        [$notice, $conflicts] = Cache::lock('index:'.hash('sha256', $directory), 180)->block(5, function () use ($operation, $directory, $data, $stashes, $request): array {
            if ($operation !== 'apply') {
                return [$stashes->saveAndSwitch($directory, $data['target'], $data['version'], $data['note'] ?? '', $request->boolean('untracked')), []];
            }
            $before = RepositoryStatus::read($directory)?->conflicts() ?? [];
            $notice = $stashes->apply($directory, $data['stash'], $data['version']);
            $after = RepositoryStatus::read($directory)?->conflicts() ?? [];

            return [$notice, array_diff_key($after, $before)];
        });
        $scanner->forgetWorkspace();
        if ($conflicts !== []) {
            $request->session()->put('stash-conflicts.'.hash('sha256', $directory),
                array_map(ConflictReview::identity(...), $conflicts));

            return to_route('repository.conflicts', $request->only('repo', 'changed') + ['file' => array_key_first($conflicts)])->with('notice', $notice);
        }

        return to_route('repository', $request->only('repo', 'changed'))->with('notice', $notice);
    }
}
