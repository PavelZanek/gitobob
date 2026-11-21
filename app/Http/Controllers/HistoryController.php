<?php

namespace App\Http\Controllers;

use App\Git\CommitHistory;
use App\Git\RepositoryScanner;
use App\Git\RevisionDiff;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class HistoryController extends Controller
{
    public function __invoke(Request $request, RepositoryScanner $scanner, CommitHistory $history, RevisionDiff $diff): View
    {
        $directory = $this->directory($request, $scanner);
        $oidRule = 'regex:/\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z/';
        $request->validate(['tip' => ['sometimes', 'required', $oidRule], 'commit' => ['sometimes', 'required', $oidRule],
            'page' => ['sometimes', 'integer', 'between:1,1000000'], 'path' => ['sometimes', 'string', 'max:4096'],
            'file' => ['sometimes', 'string', 'size:64']]);
        $path = $request->string('path')->toString();
        abort_if($path !== '' && (str_contains($path, "\0") || array_intersect(explode('/', $path), ['', '..', '.', '.git']) !== []), 404);
        $tip = $history->revision($directory, $request->query('tip', 'HEAD'));
        abort_if($request->has('tip') && $tip === null, 404);
        $commit = $request->string('commit')->toString();
        $page = $request->integer('page', 1);
        $detail = null;
        $listing = ['commits' => [], 'older' => false];
        if ($commit !== '') {
            abort_unless($history->revision($directory, $commit) === $commit, 404);
            $detail = $diff->review($directory, $diff->parentTree($directory, $commit), $commit, $request->string('file')->toString());
        } elseif ($tip !== null) {
            $listing = $history->listing($directory, $tip, $path, $page);
        }

        return view('history', $listing + ['relative' => $request->query('repo'), 'name' => basename($directory),
            'path' => $path, 'tip' => $tip, 'page' => $page, 'commit' => $commit, 'detail' => $detail,
            'summary' => $commit === '' ? null : $history->summary($directory, $commit)]);
    }
}
