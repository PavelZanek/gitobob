<?php

namespace App\Http\Controllers;

use App\Git\BranchPush;
use App\Git\FileDiff;
use App\Git\IndexActions;
use App\Git\LocalBranches;
use App\Git\RepositoryScanner;
use App\Git\RevisionDiff;
use App\Git\StagedCommit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Cache;
use Native\Desktop\Facades\Shell;
use RuntimeException;

final class IndexController extends Controller
{
    public function branches(Request $request, RepositoryScanner $scanner, LocalBranches $branches): View
    {
        $directory = $this->directory($request, $scanner);

        return view('branches', $branches->listing($directory) + ['relative' => $request->query('repo'), 'name' => basename($directory)]);
    }

    public function changeBranch(Request $request, RepositoryScanner $scanner, LocalBranches $branches): RedirectResponse
    {
        $directory = $this->directory($request, $scanner);
        $data = $request->validate(['operation' => ['required', 'in:switch,create'], 'branch' => ['required', 'string', 'max:1024'], 'version' => ['required', 'string', 'size:64']]);
        $result = Cache::lock('index:'.hash('sha256', $directory), 120)->block(5,
            fn (): array => $branches->change($directory, $data['version'], $data['branch'], $data['operation'] === 'create'));
        $scanner->forgetWorkspace();

        $back = to_route($result['ok'] ? 'repository' : 'repository.branches', $request->only('repo', 'changed'))->with('notice', $result['message']);
        if ($data['operation'] === 'switch' && ! $result['ok'] && str_contains($result['message'], 'would be overwritten')) {
            $back->with('blockedBranch', $data['branch']);
        }

        return $back;
    }

    public function change(Request $request, RepositoryScanner $scanner, IndexActions $index): RedirectResponse
    {
        $directory = $this->directory($request, $scanner);
        $data = $request->validate([
            'file' => ['required', 'string', 'size:64'],
            'section' => ['required', 'in:Staged,Unstaged,Untracked'],
            'version' => ['required', 'string', 'size:64'],
            'hunk' => ['sometimes', 'integer', 'min:0'],
            'intent' => ['sometimes', 'in:lines'],
            'lines' => ['required_if:intent,lines', 'nullable', 'string', 'max:1048576', 'regex:/^\d+(?:,\d+)*$/D'],
            'patch_version' => ['required_with:hunk,intent', 'string', 'size:64'],
        ]);
        $message = Cache::lock('index:'.hash('sha256', $directory), 120)->block(5,
            fn (): string => $index->change($directory, $data['file'], $data['section'], $data['version'], isset($data['hunk']) ? (int) $data['hunk'] : null, $data['patch_version'] ?? null,
                isset($data['intent']) ? array_map(intval(...), explode(',', $data['lines'])) : null));
        $scanner->forgetWorkspace();

        return to_route($request->boolean('all') ? 'changes' : 'repository', $request->only('repo', 'file', 'filter', 'changed'))->with('notice', $message);
    }

    public function preview(Request $request, RepositoryScanner $scanner, StagedCommit $commit, FileDiff $diff): View
    {
        $directory = $this->directory($request, $scanner);
        $review = $commit->preview($directory);

        return view('commit', $review + ['relative' => $request->query('repo'), 'name' => basename($directory),
            'patch' => $review['summary'] === '' ? '' : $diff->staged($directory)]);
    }

    public function commit(Request $request, RepositoryScanner $scanner, StagedCommit $commit): RedirectResponse
    {
        $directory = $this->directory($request, $scanner);
        $data = $request->validate(['version' => ['required', 'string', 'size:64'], 'message' => ['required', 'string', 'max:16000']]);
        $back = to_route('repository.commit.preview', $request->only('repo'))->withInput($request->only('message'));
        try {
            $created = Cache::lock('index:'.hash('sha256', $directory), 120)->block(5,
                fn (): ?string => $commit->create($directory, $data['version'], $data['message']));
        } catch (RuntimeException) {
            return $back->with('notice', 'Git could not create the commit. Check your Git identity, signing and hooks.');
        }
        if (! $created) {
            return $back->with('notice', 'The branch or staged changes changed. Review the updated preview before committing.');
        }
        $scanner->forgetWorkspace();

        return to_route('repository', $request->only('repo'))->with('notice', 'Local commit created.')->with('committed', $created);
    }

    public function amend(Request $request, RepositoryScanner $scanner, StagedCommit $commit, BranchPush $push, FileDiff $diff): View|RedirectResponse
    {
        $directory = $this->directory($request, $scanner);
        $back = to_route('repository', $request->only('repo'));
        $submit = $request->routeIs('repository.amend');
        $data = $submit ? $request->validate(['version' => ['required', 'string', 'size:64'], 'message' => ['required', 'string', 'max:16000']]) : [];
        try {
            return Cache::lock('index:'.hash('sha256', $directory), 180)->block(5, function () use ($request, $submit, $data, $directory, $back, $commit, $push, $diff, $scanner): View|RedirectResponse {
                $review = $commit->amendPreview($directory, $push, ! $submit);
                if (! $submit) {
                    return view('commit', $review + ['amend' => true, 'relative' => $request->query('repo'), 'name' => basename($directory),
                        'patch' => $review['summary'] === '' ? '' : $diff->staged($directory)]);
                }
                if (! hash_equals($review['version'], $data['version'])) {
                    return $back->with('notice', 'The branch, upstream or staged changes changed. Review the amendment again.');
                }
                $created = $commit->create($directory, $review['indexVersion'], $data['message'], $review['head']);
                if ($created === null) {
                    return $back->with('notice', 'The staged snapshot changed. Review the amendment again.');
                }
                $scanner->forgetWorkspace();

                return $back->with('notice', 'Local commit amended.')->with('committed', $created);
            });
        } catch (RuntimeException $exception) {
            return $back->with('notice', $exception instanceof ProcessFailedException ? 'Git could not amend the commit. Check signing and hooks.' : $exception->getMessage());
        }
    }

    public function pushPreview(Request $request, RepositoryScanner $scanner, BranchPush $push, RevisionDiff $diff): View|RedirectResponse
    {
        $directory = $this->directory($request, $scanner);
        $snapshot = $request->string('review')->toString();
        $cacheKey = 'sync-review:'.hash('sha256', $directory).':';
        if ($snapshot === '') {
            $review = $push->preview($directory, $request->string('remote')->toString());
            if ($review['relation'] !== '') {
                Cache::put($cacheKey.$review['version'], $review, now()->addMinutes(30));
            }
        } else {
            $review = Cache::get($cacheKey.$snapshot);
            if ($review === null) {
                return to_route('repository.push.preview', $request->only('repo'))->with('notice', 'Your comparison expired. Showing the current branch.');
            }
        }

        $commit = $request->string('commit')->toString();
        $detail = null;
        $title = 'Incoming changes';
        if ($commit !== '') {
            $title = $review['local']['subjects'][$commit] ?? $review['incoming']['subjects'][$commit] ?? null;
            abort_if($title === null, 404);
            $base = $diff->parentTree($directory, $commit);
            $tip = $commit;
        } elseif ($review['relation'] === 'behind') {
            $base = $review['head'];
            $tip = $review['tip'];
        }
        if (isset($base, $tip)) {
            $detail = $diff->review($directory, $base, $tip, $request->string('file')->toString());
        }

        return view('push', $review + ['relative' => $request->query('repo'), 'name' => basename($directory),
            'detail' => $detail, 'detailTitle' => $title, 'commit' => $commit]);
    }

    public function publish(Request $request, RepositoryScanner $scanner, BranchPush $push): RedirectResponse
    {
        $directory = $this->directory($request, $scanner);
        $data = $request->validate(['version' => ['required', 'string', 'size:64'], 'remote' => ['sometimes', 'string']]);
        $result = Cache::lock('index:'.hash('sha256', $directory), 120)->block(5,
            fn (): array => $push->publish($directory, $data['version'], $data['remote'] ?? ''));
        $back = to_route('repository.push.preview', $request->only('repo', 'remote', 'comparison', 'file_back', 'tab', 'page'))->with('notice', $result['notice']);
        if ($result['compareUrl'] !== null) {
            $key = hash('sha256', $directory.$result['compareUrl']);
            $request->session()->put('publishedComparison', ['directory' => $directory, 'key' => $key, 'url' => $result['compareUrl']]);
            $back->with('openComparison', $key);
        }

        return $back;
    }

    public function openComparison(Request $request, RepositoryScanner $scanner): Response
    {
        $directory = $this->directory($request, $scanner);
        $comparison = $request->session()->get('publishedComparison');
        abort_unless($comparison && $comparison['directory'] === $directory && $comparison['key'] === $request->input('key'), 404);
        Shell::openExternal($comparison['url']);

        return response()->noContent();
    }

    public function sync(Request $request, RepositoryScanner $scanner, BranchPush $push, string $operation): RedirectResponse
    {
        $directory = $this->directory($request, $scanner);
        $data = $request->validate(['version' => ['required', 'string', 'size:64']]);
        $notice = Cache::lock('index:'.hash('sha256', $directory), 120)->block(5,
            fn (): string => match ($operation) {
                'fetch' => $push->fetch($directory, $data['version']),
                'pull' => $push->pull($directory, $data['version']),
                'push' => $push->send($directory, $data['version']),
                default => abort(404),
            });
        if ($request->boolean('workflow') && (str_starts_with($notice, 'Pushed ') || str_starts_with($notice, 'Fast-forwarded ') || str_starts_with($notice, 'Fetched ') || $notice === 'Up to date')) {
            $scanner->forgetWorkspace();

            return to_route('repository', $request->only('repo'))->with('notice', $notice);
        }
        if ($operation === 'pull') {
            $scanner->forgetWorkspace();
        }

        return to_route('repository.push.preview', $request->only('repo', 'remote', 'comparison', 'file_back', 'tab', 'page'))->with('notice', $notice);
    }
}
