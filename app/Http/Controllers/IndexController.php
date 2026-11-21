<?php

namespace App\Http\Controllers;

use App\Git\BranchPush;
use App\Git\FileDiff;
use App\Git\IndexActions;
use App\Git\RepositoryScanner;
use App\Git\StagedCommit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class IndexController
{
    private function directory(Request $request, RepositoryScanner $scanner): string
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

    public function change(Request $request, RepositoryScanner $scanner, IndexActions $index): RedirectResponse
    {
        $directory = $this->directory($request, $scanner);
        $data = $request->validate([
            'file' => ['required', 'string', 'size:64'],
            'section' => ['required', 'in:Staged,Unstaged,Untracked'],
            'version' => ['required', 'string', 'size:64'],
            'hunk' => ['sometimes', 'integer', 'min:0'],
            'patch_version' => ['required_with:hunk', 'string', 'size:64'],
        ]);
        $message = Cache::lock('index:'.hash('sha256', $directory), 120)->block(5,
            fn (): string => $index->change($directory, $data['file'], $data['section'], $data['version'], isset($data['hunk']) ? (int) $data['hunk'] : null, $data['patch_version'] ?? null));
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
                fn (): bool => $commit->create($directory, $data['version'], $data['message']));
        } catch (RuntimeException) {
            return $back->with('notice', 'Git could not create the commit. Check your Git identity, signing and hooks.');
        }
        if (! $created) {
            return $back->with('notice', 'The branch or staged changes changed. Review the updated preview before committing.');
        }
        $scanner->forgetWorkspace();

        return to_route('repository', $request->only('repo'))->with('notice', 'Local commit created.')->with('committed', true);
    }

    public function pushPreview(Request $request, RepositoryScanner $scanner, BranchPush $push): View
    {
        $directory = $this->directory($request, $scanner);

        return view('push', $push->preview($directory) + ['relative' => $request->query('repo'), 'name' => basename($directory)]);
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
        if ($operation === 'pull') {
            $scanner->forgetWorkspace();
        }

        return to_route('repository.push.preview', $request->only('repo'))->with('notice', $notice);
    }
}
