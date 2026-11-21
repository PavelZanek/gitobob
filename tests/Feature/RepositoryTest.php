<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    $this->root = Storage::disk('local')->path('workspace');
    $this->repo = $this->root.'/client';
    File::ensureDirectoryExists($this->repo);
    Process::run(['git', 'init', '-b', 'dev', $this->repo])->throw();
    Storage::disk('local')->put('workspace.path', $this->root);
});

afterEach(function (): void {
    File::deleteDirectory(Storage::disk('local')->path(''));
});

it('lists a partially staged file once or in both groups and loads its two diffs on selection', function (): void {
    $path = 'notes [draft].txt';
    file_put_contents($this->repo.'/'.$path, "index version\n");
    Process::run(['git', '-C', $this->repo, 'add', '--', $path])->throw();
    file_put_contents($this->repo.'/'.$path, "working version\n");
    $url = '/repository?repo=client';
    $this->get('/')->assertSee('repo=client', false);
    $flat = $this->get($url)->assertOk()->assertSee('Staged · Unstaged')->assertDontSee('index version');
    expect(substr_count($flat->getContent(), $path))->toBe(1);
    $grouped = $this->get($url.'&layout=grouped')->assertOk()->assertCookie('file_layout', 'grouped')->assertSee('Added')->assertSee('Modified');
    expect(substr_count($grouped->getContent(), $path))->toBe(2);
    $this->withCookie('file_layout', 'grouped')->get($url)->assertSee('Modified');
    $this->get($url.'&layout=all')->assertCookie('file_layout', 'all');

    $this->get($url.'&file='.hash('sha256', $path))->assertOk()
        ->assertSeeInOrder(['Staged diff', '+index version', 'Unstaged diff', '-index version', '+working version']);
});

it('previews a renamed file, binary content and new files with literal paths', function (): void {
    file_put_contents($this->repo.'/old.txt', "before\n");
    Process::run(['git', '-C', $this->repo, 'add', '.'])->throw();
    Process::run(['git', '-C', $this->repo, '-c', 'user.name=Test', '-c', 'user.email=test@example.org', 'commit', '-m', 'Initial'])->throw();
    Process::run(['git', '-C', $this->repo, 'mv', 'old.txt', 'new.txt'])->throw();
    $url = '/repository?repo=client&file=';
    $this->get($url.hash('sha256', 'new.txt'))->assertSee('rename from old.txt')->assertSee('rename to new.txt');
    $path = '-new [draft].txt';
    file_put_contents($this->repo.'/'.$path, "<example>\n");
    $this->get($url.hash('sha256', $path))->assertSee('Untracked diff')->assertSee('+<example>')->assertDontSee('<example>', false);
    file_put_contents($this->repo.'/image.bin', "\0data");
    $this->get($url.hash('sha256', 'image.bin'))->assertSee('Binary files');
    file_put_contents($this->repo.'/large.txt', str_repeat("new line\n", 35000));
    $this->get($url.hash('sha256', 'large.txt'))->assertSee('Preview limited to 256 KiB.');
});

it('handles stale selections and restricts details to repositories in the chosen workspace', function (): void {
    $this->get('/repository?repo=client&file=missing')->assertOk()->assertSee('This repository is clean')->assertSee('no longer in the change list');
    $this->get('/repository?repo=..')->assertNotFound();
    $this->get('/repository?repo=missing')->assertNotFound();
    symlink($this->repo, $this->root.'/alias');
    $this->get('/repository?repo=alias')->assertNotFound();
    $this->get('/repository?repo=.')->assertNotFound();
    Process::run(['git', 'init', $this->root.'/Space project '])->throw();
    $this->get('/repository?repo=Space%20project%20')->assertOk()->assertSee('This repository is clean');
    Storage::disk('local')->put('workspace.path', $this->repo);
    $this->get('/repository?repo=.')->assertOk()->assertSee('This repository is clean');
});
