<?php

namespace App\Http\Controllers;

use App\Git\BranchComparison;
use App\Git\CommitHistory;
use App\Git\GitHubClient;
use App\Git\RepositoryScanner;
use App\Git\RevisionDiff;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Native\Desktop\Facades\Shell;
use RuntimeException;

/** @phpstan-import-type Pair from BranchComparison */
final class CompareController extends Controller
{
    public function index(Request $request, RepositoryScanner $scanner, BranchComparison $comparison, CommitHistory $history, RevisionDiff $diff): View
    {
        $directory = $this->directory($request, $scanner);
        $branches = $comparison->branches($directory);
        $refs = $branches['refs'];
        $relative = $request->query('repo');
        $snapshot = $request->string('comparison')->toString();
        $pair = $snapshot === '' ? null : $this->snapshot($directory, $snapshot);
        $head = $request->string('head', $branches['current'])->toString();
        $base = $request->string('base')->toString();
        if ($pair !== null) {
            $head = 'refs/heads/'.$pair['head']['name'];
            $base = ($pair['base']['local'] ? 'refs/heads/' : 'refs/remotes/').$pair['base']['name'];
        } else {
            $choices = array_values(array_diff(array_keys($refs), [$head]));
            if ($base === '') {
                $preferred = array_intersect(['refs/heads/dev', 'refs/remotes/origin/dev', 'refs/heads/main', 'refs/remotes/origin/main'], $choices);
                $base = array_values($preferred)[0] ?? $choices[0] ?? '';
            }
            if (isset($refs[$base], $refs[$head]) && $refs[$head]['local'] && $head !== $base) {
                $pair = $comparison->pair($directory, $refs[$base], $refs[$head]);
                $snapshot = hash('sha256', serialize($pair));
                Cache::put($this->key($directory, $snapshot), $pair, now()->addDay());
            }
        }
        $tab = $request->query('tab') === 'commits' ? 'commits' : 'files';
        $page = max(1, min(1000000, $request->integer('page', 1)));
        $file = $request->string('file')->toString();
        $detail = null;
        $listing = ['commits' => [], 'older' => false];
        if ($pair !== null && $pair['ancestor'] !== '') {
            if ($tab === 'commits') {
                $listing = $history->listing($directory, $pair['base']['oid'].'..'.$pair['head']['oid'], '', $page);
            } else {
                $detail = $diff->review($directory, $pair['ancestor'], $pair['head']['oid'], $file);
                $file = $detail['selected'] ? hash('sha256', $detail['selected']->path) : '';
            }
        }

        return view('compare', $listing + compact('refs', 'relative', 'snapshot', 'pair', 'head', 'base', 'tab', 'page', 'file', 'detail') + ['name' => basename($directory)]);
    }

    public function action(Request $request, RepositoryScanner $scanner, BranchComparison $comparison, GitHubClient $github): View|Response|RedirectResponse
    {
        $directory = $this->directory($request, $scanner);
        $snapshot = $request->string('comparison')->toString();
        $pair = $this->snapshot($directory, $snapshot);
        abort_unless($pair !== null && $pair['ancestor'] !== '' && $pair['ancestor'] !== $pair['head']['oid'], 404);
        try {
            $action = $comparison->action($directory, $pair, $github);
        } catch (RuntimeException $exception) {
            if (! in_array($exception->getCode(), [401, 403, 503], true)) {
                throw $exception;
            }
            $action = ['label' => '', 'message' => $exception->getMessage()];
        }
        $query = $request->only('repo', 'comparison', 'file', 'tab', 'page');
        if ($request->isMethod('post')) {
            if (! isset($action['url']) || ! hash_equals(hash('sha256', $action['url']), $request->string('destination')->toString())) {
                return to_route('repository.compare', $query)->with('notice', $action['message'] ?: 'The pull request changed. Review the updated action.');
            }
            Shell::openExternal($action['url']);

            return response()->noContent();
        }

        return view('compare-action', compact('action', 'query') + ['remote' => $pair['head']['remote']]);
    }

    /** @return Pair|null */
    private function snapshot(string $directory, string $snapshot): ?array
    {
        return Cache::get($this->key($directory, $snapshot));
    }

    private function key(string $directory, string $snapshot): string
    {
        return 'branch-comparison:'.hash('sha256', $directory.'|'.$snapshot);
    }
}
