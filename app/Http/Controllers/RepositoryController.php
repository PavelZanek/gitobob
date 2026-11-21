<?php

namespace App\Http\Controllers;

use App\Git\FileDiff;
use App\Git\RepositoryScanner;
use App\Git\RepositoryStatus;
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

        $grouped = $request->query('layout', $request->cookie('file_layout')) === 'grouped';
        $groups = array_fill_keys($grouped ? ['Staged', 'Unstaged', 'Untracked'] : ['All files'], []);
        $previews = [];
        foreach ($status->files as $id => $file) {
            foreach ($grouped ? array_keys($file->sections) : ['All files'] as $section) {
                $groups[$section][$id] = $file;
            }
        }
        $groups = array_filter($groups);
        $firstGroup = reset($groups);
        $defaultFile = $firstGroup === false ? null : array_key_first($firstGroup);
        $fileId = $request->query('file', $defaultFile);
        $selected = is_string($fileId) ? ($status->files[$fileId] ?? null) : null;
        $showDiff = $request->query('diff') !== 'hidden';
        if ($showDiff && $selected !== null) {
            foreach (array_keys($selected->sections) as $section) {
                $previews[$section] = $diff->preview($directory, $selected, $section);
            }
        }

        $response = response()->view('repository', [
            'name' => basename($directory) ?: '/', 'relative' => $relative, 'status' => $status,
            'grouped' => $grouped, 'groups' => $groups, 'fileId' => $fileId, 'selected' => $selected,
            'showDiff' => $showDiff, 'previews' => $previews, 'onlyChanged' => $request->boolean('changed'),
        ]);
        if ($request->has('layout')) {
            $response->withCookie(cookie()->forever('file_layout', $grouped ? 'grouped' : 'all'));
        }

        return $response;
    }
}
