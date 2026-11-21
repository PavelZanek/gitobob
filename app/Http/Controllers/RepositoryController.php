<?php

namespace App\Http\Controllers;

use App\Git\FileDiff;
use App\Git\RepositoryScanner;
use App\Git\RepositoryStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

final class RepositoryController
{
    public function __invoke(Request $request, RepositoryScanner $scanner, FileDiff $diff): Response
    {
        $root = Storage::disk('local')->get('workspace.path');
        $relative = $request->query('repo', '.');
        abort_unless(is_string($root) && is_string($relative), 404);
        $directory = $scanner->repositoryDirectory($root, $relative);
        abort_if($directory === null, 404);
        $status = RepositoryStatus::read($directory);
        abort_if($status === null, 404);

        return $this->review($request, $scanner, $diff, $root, [$relative => $status], $relative);
    }

    public function changes(Request $request, RepositoryScanner $scanner, FileDiff $diff): Response|RedirectResponse
    {
        $root = Storage::disk('local')->get('workspace.path');
        if (! is_string($root) || ! is_dir($root) || ! is_readable($root)) {
            return to_route('workspace');
        }

        $reuse = $request->boolean('snapshot');
        $repositories = $scanner->workspaceTree($root, $reuse)?->changedRepositories() ?? [];
        $relative = $request->query('repo', (string) array_key_first($repositories));
        abort_unless(is_string($relative), 404);

        if ($reuse) {
            // The sidebar can reuse the scan, but a patch must reflect the current index and worktree.
            while (isset($repositories[$relative])) {
                $directory = $scanner->repositoryDirectory($root, $relative);
                $status = $directory === null ? null : RepositoryStatus::read($directory);
                if ($status !== null && $status->changedFiles > 0) {
                    $repositories[$relative] = $status;
                    break;
                }

                unset($repositories[$relative]);
                if ($request->has('repo')) {
                    break;
                }
                $relative = (string) array_key_first($repositories);
            }
        }

        return $this->review($request, $scanner, $diff, $root, $repositories, $relative);
    }

    /** @param array<string, RepositoryStatus> $repositories */
    private function review(Request $request, RepositoryScanner $scanner, FileDiff $diff, string $root, array $repositories, string $relative): Response
    {
        $allChanges = $request->routeIs('changes');
        $grouped = $request->query('layout', $request->cookie('file_layout')) === 'grouped';
        $groups = array_map(static fn (RepositoryStatus $repo): array => $repo->groups($grouped), $repositories);
        $visible = $groups[$relative] ?? [];
        $firstGroup = reset($visible);
        $fileId = $request->query('file', $firstGroup === false ? null : array_key_first($firstGroup));
        $selected = is_string($fileId) ? ($repositories[$relative]->files[$fileId] ?? null) : null;
        $previews = [];
        $showDiff = $request->query('diff') !== 'hidden';
        if ($showDiff && $selected !== null) {
            $directory = $scanner->repositoryDirectory($root, $relative);
            abort_if($directory === null, 404);
            foreach (array_keys($selected->sections) as $section) {
                $previews[$section] = $diff->preview($directory, $selected, $section);
            }
        }

        $response = response()->view('repository', [
            'name' => $allChanges ? 'All changes' : (basename($relative === '.' ? $root : $relative) ?: '/'),
            'rootName' => basename($root) ?: '/', 'relative' => $relative, 'repositories' => $repositories,
            'allChanges' => $allChanges, 'total' => array_sum(array_column($repositories, 'changedFiles')),
            'grouped' => $grouped, 'groups' => $groups, 'fileId' => $fileId, 'selected' => $selected,
            'showDiff' => $showDiff, 'previews' => $previews, 'onlyChanged' => $request->boolean('changed'),
        ]);
        if ($request->has('layout')) {
            $response->withCookie(cookie()->forever('file_layout', $grouped ? 'grouped' : 'all'));
        }

        return $response;
    }
}
