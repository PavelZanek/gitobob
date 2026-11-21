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
        $query = $request->only('repo', 'comparison', 'head', 'base', 'file', 'tab', 'page');
        if ($request->isMethod('post')) {
            if (! isset($action['url']) || ! hash_equals(hash('sha256', $action['url']), $request->string('destination')->toString())) {
                return to_route('repository.compare', $query)->with('notice', $action['message'] ?: 'The pull request changed. Review the updated action.');
            }
            Shell::openExternal($action['url']);

            return response()->noContent();
        }

        return view('compare-action', compact('action', 'query') + ['remote' => $pair['head']['remote']]);
    }

    public function compose(Request $request, RepositoryScanner $scanner, BranchComparison $comparison, GitHubClient $github, CommitHistory $history): View|RedirectResponse
    {
        $directory = $this->directory($request, $scanner);
        $query = $request->only('repo', 'comparison', 'base', 'head', 'file', 'page', 'tab');
        $pair = $this->snapshot($directory, $request->string('comparison')->toString());
        $fresh = array_diff_key($query, ['comparison' => true]);
        if ($pair !== null) {
            $fresh['head'] = 'refs/heads/'.$pair['head']['name'];
            $fresh['base'] = ($pair['base']['local'] ? 'refs/heads/' : 'refs/remotes/').$pair['base']['name'];
        }
        $identity = $request->string('identity')->toString();
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $identity), 404);
        $key = 'pull-compose:'.hash('sha256', serialize([$directory, $fresh['head'] ?? '', $fresh['base'] ?? '', $identity]));
        $draft = $request->session()->get($key);
        if ($request->isMethod('post')) {
            $data = $request->validate(['title' => [$request->input('intent') === 'review' ? 'nullable' : 'required', 'string', 'max:256'],
                'body' => ['nullable', 'string', 'max:65536'], 'mode' => ['required', 'in:draft,ready'], 'intent' => ['required', 'in:review,create']]);
            $draft = ['title' => $data['title'] ?? '', 'body' => $data['body'] ?? '', 'mode' => $data['mode']];
            $request->session()->put($key, $draft);
        }
        if ($pair === null) {
            return to_route('repository.compare', $fresh)->with('notice', 'This comparison expired. Review the current branches.');
        }
        $base = $pair['base'];
        $head = $pair['head'];
        abort_if($pair['ancestor'] === '' || $pair['ancestor'] === $pair['head']['oid'], 404);
        if ($request->isMethod('post') && $request->input('intent') === 'review') {
            return to_route('repository.compare', $query);
        }
        try {
            $action = $comparison->action($directory, $pair, $github);
            if (isset($action['number'], $action['repository'])) {
                return $this->reviewPull($request, $action['repository'], $action['number']);
            }
            if (! isset($action['source'], $action['repository'], $action['url']) || ! hash_equals(hash('sha256', $action['url']), $identity)) {
                return to_route('repository.compare', $fresh)->with('notice', $action['message'] ?: 'The GitHub destination changed. Review the updated comparison.');
            }
            if ($request->isMethod('post')) {
                try {
                    $number = $github->createPull($action['repository'], $base['target'], $action['source'], $head['target'], $draft['title'], $draft['body'], $draft['mode'] === 'draft');
                } catch (RuntimeException $exception) {
                    $number = $github->matchingPull($action['repository'], $base['target'], $action['source'], $head['target']);
                    if ($number === null) {
                        throw $exception;
                    }
                }
                $request->session()->forget($key);

                return $this->reviewPull($request, $action['repository'], $number);
            }
        } catch (RuntimeException $exception) {
            if (! in_array($exception->getCode(), [401, 403, 422, 503], true)) {
                throw $exception;
            }

            return to_route('repository.compare', $query)->with('notice', $exception->getMessage());
        }
        if ($draft === null) {
            $entries = $history->listing($directory, $base['oid'].'..'.$head['oid'], '', 1)['commits'];
            $draft = ['title' => count($entries) === 1 ? $entries[0]['subject'] : '', 'body' => '', 'mode' => 'draft'];
        }

        return view('pull-compose', compact('query', 'identity', 'draft', 'head', 'base', 'action'));
    }

    private function reviewPull(Request $request, string $repository, int $number): RedirectResponse
    {
        return to_route('cloud.pull', ['repository' => $repository, 'number' => $number, 'locate' => $request->filled('file') ? 1 : null, 'file' => $request->query('file')]);
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
