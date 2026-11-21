<?php

namespace App\Http\Controllers;

use App\Git\ConflictReview;
use App\Git\RepositoryScanner;
use App\Git\RepositoryStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

final class ConflictController extends Controller
{
    public function index(Request $request, RepositoryScanner $scanner, ConflictReview $review): Response
    {
        $directory = $this->directory($request, $scanner);
        $status = RepositoryStatus::read($directory);
        abort_if($status === null, 404);
        $files = $status->conflicts();
        $id = $request->string('file')->toString();
        $id = isset($files[$id]) ? $id : array_key_first($files);
        $selected = $files[$id] ?? null;
        $incoming = 'Incoming version';
        if ($selected !== null) {
            $known = $request->session()->get('stash-conflicts.'.hash('sha256', $directory), []);
            if (($known[$id] ?? null) === ConflictReview::identity($selected)) {
                $incoming = 'Stashed changes';
            }
        }
        $sides = $selected === null ? [] : $review->sides($directory, $selected);
        $working = $selected === null ? null : $review->working($directory, $selected, $status->branch);
        $revision = hash('sha256', serialize([$status->branch, $files, $id, $working, $incoming]));
        if ($request->header('X-GitoBob-Revision') === $revision) {
            return response('', 204);
        }

        return response()->view('conflicts', ['relative' => $request->query('repo'), 'name' => basename($directory), 'branch' => $status->branch,
            'files' => $files, 'id' => $id, 'selected' => $selected, 'sides' => $sides, 'working' => $working, 'incoming' => $incoming, 'revision' => $revision]);
    }

    public function editor(Request $request, RepositoryScanner $scanner, ConflictReview $review): RedirectResponse|Response
    {
        $directory = $this->directory($request, $scanner);
        $status = RepositoryStatus::read($directory);
        $file = $status?->conflicts()[$request->string('file')->toString()] ?? null;
        abort_unless($status && $file && $review->working($directory, $file, $status->branch)['editable'], 404);

        return $review->openEditor($directory.'/'.$file->path) ? response()->noContent()
            : to_route('repository.conflicts', $request->only('repo', 'file'))->with('notice', 'Could not open your text editor.');
    }

    public function resolve(Request $request, RepositoryScanner $scanner, ConflictReview $review): RedirectResponse
    {
        $directory = $this->directory($request, $scanner);
        $data = $request->validate(['file' => ['required', 'string', 'size:64'], 'version' => ['required', 'string', 'size:64']]);
        $ok = Cache::lock('index:'.hash('sha256', $directory), 120)->block(5, fn (): bool => $review->resolve($directory, $data['file'], $data['version']));
        if (! $ok) {
            return to_route('repository.conflicts', $request->only('repo', 'file'))->with('notice', 'Review the current working file before marking it as resolved.');
        }
        $scanner->forgetWorkspace();
        if (RepositoryStatus::read($directory)?->conflicts() === []) {
            return to_route('repository.commit.preview', $request->only('repo'))->with('notice', 'All conflicts resolved. Review the staged changes.');
        }

        return to_route('repository.conflicts', $request->only('repo'))->with('notice', 'File marked as resolved.');
    }
}
