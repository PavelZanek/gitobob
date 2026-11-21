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

it('opens both diffs for a partially staged file and can collapse the preview in either layout', function (): void {
    $path = 'notes [draft].txt';
    file_put_contents($this->repo.'/'.$path, "index version\n");
    Process::run(['git', '-C', $this->repo, 'add', '--', $path])->throw();
    file_put_contents($this->repo.'/'.$path, "working version\n");
    $url = '/repository?repo=client';
    $this->get('/')->assertSee('repo=client', false);
    $this->get($url)->assertOk()->assertSee('Hide diff')
        ->assertSeeInOrder(['Staged diff', '+index version', 'Unstaged diff', '-index version', '+working version']);
    $flat = $this->get($url.'&diff=hidden')->assertOk()->assertSee('Show diff')
        ->assertSee('Staged · Unstaged')->assertDontSee('index version')->assertDontSee('aria-label="File diff"', false);
    expect(substr_count($flat->getContent(), $path))->toBe(1);
    $grouped = $this->get($url.'&layout=grouped&diff=hidden')->assertOk()->assertCookie('file_layout', 'grouped')->assertSee('Added')->assertSee('Modified');
    expect(substr_count($grouped->getContent(), $path))->toBe(2);
    $this->withCookie('file_layout', 'grouped')->get($url)->assertSee('Modified');
    $this->get($url.'&layout=all')->assertCookie('file_layout', 'all');

    $this->get($url.'&file='.hash('sha256', $path))->assertOk()
        ->assertSeeInOrder(['Staged diff', '+index version', 'Unstaged diff', '-index version', '+working version']);
});

it('opens the first visible file and keeps an explicit selection when the preview is reopened', function (): void {
    file_put_contents($this->repo.'/alpha.txt', "alpha preview\n");
    file_put_contents($this->repo.'/zulu.txt', "zulu preview\n");
    Process::run(['git', '-C', $this->repo, 'add', 'zulu.txt'])->throw();

    $url = '/repository?repo=client';
    $this->get($url)->assertSee('+alpha preview')->assertDontSee('+zulu preview');
    $this->get($url.'&layout=grouped')->assertSee('+zulu preview')->assertDontSee('+alpha preview');

    $selection = $url.'&file='.hash('sha256', 'zulu.txt');
    $this->get($selection.'&diff=hidden')->assertSee('Show diff')->assertDontSee('+zulu preview')
        ->assertSee('href="'.e(url($selection)).'">Show diff', false);
    $this->get($selection)->assertSee('+zulu preview')->assertDontSee('+alpha preview');
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
    $this->get('/repository?repo=.')->assertOk()->assertSee('This repository is clean')
        ->assertDontSee('Hide diff')->assertDontSee('Show diff')->assertDontSee('aria-label="File diff"', false);
});
