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

it('switches between matching paths in different repositories within the workspace review', function (): void {
    $plugin = $this->root.'/plugins/123';
    File::ensureDirectoryExists($plugin);
    Process::run(['git', 'init', '-b', 'feature/plugin', $plugin])->throw();
    file_put_contents($this->repo.'/config.txt', "core version\n");
    file_put_contents($plugin.'/config.txt', "plugin index\n");
    Process::run(['git', '-C', $plugin, 'add', 'config.txt'])->throw();
    file_put_contents($plugin.'/config.txt', "plugin edits\n");
    Process::run(['git', 'init', $this->root.'/clean-project'])->throw();

    $id = hash('sha256', 'config.txt');
    $this->get('/')->assertSee('All changes');
    $this->get('/changes')->assertOk()->assertSee('2 repositories')->assertSee('2 changed files')
        ->assertSee('plugins/123')->assertDontSee('clean-project')->assertSee('+core version')->assertDontSee('plugin edits');
    $selection = '/changes?repo=plugins%2F123&file='.$id;
    $this->get($selection.'&layout=grouped')->assertCookie('file_layout', 'grouped')
        ->assertSee('feature/plugin')->assertSeeInOrder(['Staged diff', '+plugin index', 'Unstaged diff', '+plugin edits'])
        ->assertDontSee('+core version');
    $this->get($selection.'&diff=hidden')->assertSee('Show diff')->assertDontSee('+plugin edits');

    File::deleteDirectory($plugin);
    $this->get($selection)->assertOk()->assertSee('no longer in the change list')->assertDontSee('+core version');
    $this->get('/changes?repo=..&file='.$id)->assertDontSee('+core version');
});

it('handles a workspace root repository, a clean workspace and an unavailable folder in all changes', function (): void {
    Storage::disk('local')->put('workspace.path', $this->repo);
    $this->get('/changes')->assertOk()->assertSee('All repositories are clean.')->assertDontSee('Hide diff');
    file_put_contents($this->repo.'/root.txt', "root change\n");
    $this->get('/changes')->assertSee('client')->assertSee('+root change');
    Storage::disk('local')->put('workspace.path', $this->root.'/missing');
    $this->get('/changes')->assertRedirect('/');
});

it('reuses the workspace scan for navigation while reading the selected repository live', function (): void {
    file_put_contents($this->repo.'/notes.txt', "workspace version\n");
    $this->get('/')->assertOk()->assertSee('changes?snapshot=1', false);
    Process::run(['git', 'init', $this->root.'/new-repo'])->throw();
    file_put_contents($this->root.'/new-repo/new.txt', 'new repository');
    Process::run(['git', '-C', $this->repo, 'add', 'notes.txt'])->throw();
    file_put_contents($this->repo.'/notes.txt', "latest version\n");

    $this->get('/changes?snapshot=1')->assertOk()->assertDontSee('new-repo')
        ->assertSeeInOrder(['Staged diff', '+workspace version', 'Unstaged diff', '+latest version']);
    $this->get('/changes')->assertOk()->assertSee('new-repo');
    $this->get('/?snapshot=1')->assertOk()->assertSee('new-repo');

    File::deleteDirectory($this->repo);
    $this->get('/changes?snapshot=1&repo=client&file='.hash('sha256', 'notes.txt'))
        ->assertOk()->assertSee('no longer in the change list')->assertDontSee('+workspace version');
    $this->get('/changes?snapshot=1')->assertOk()->assertSee('+new repository');
});

it('expires workspace snapshots and isolates them by folder', function (): void {
    $this->get('/')->assertOk();
    file_put_contents($this->repo.'/later.txt', 'appeared later');
    $this->get('/changes?snapshot=1')->assertSee('All repositories are clean.');
    $this->travel(6)->minutes();
    $this->get('/changes?snapshot=1')->assertSee('+appeared later');

    $other = $this->root.'/another';
    Process::run(['git', 'init', $other])->throw();
    Storage::disk('local')->put('workspace.path', $other);
    $this->get('/changes?snapshot=1')->assertOk()->assertSee('All repositories are clean.')->assertDontSee('later.txt');
});

it('refreshes the selected working diff and advances when it leaves the change list', function (): void {
    foreach (['a', 'b', 'c'] as $name) {
        file_put_contents($this->repo.'/'.$name, $name." original\n");
    }
    $url = '/repository?repo=client&slot=1&file='.hash('sha256', 'b');
    $revision = $this->get($url)->assertSee('+b original')->viewData('revision');
    $headers = ['X-GitoBob-Revision' => $revision];
    $this->get($url, $headers)->assertNoContent();
    file_put_contents($this->repo.'/b', "edited outside GitoBob\n");
    $this->get($url, $headers)->assertSee('+edited outside GitoBob');
    unlink($this->repo.'/b');
    $this->get($url, $headers)->assertSee('+c original')->assertDontSee('no longer in the change list');
    unlink($this->repo.'/a');
    unlink($this->repo.'/c');
    $this->get($url, $headers)->assertSee('This repository is clean.')->assertDontSee('aria-label="File diff"', false);
});

it('translates review controls while preserving Git paths, patch content and section identifiers', function (): void {
    $this->post('/settings', ['theme' => 'system', 'depth' => 5, 'language' => 'cs']);
    file_put_contents($this->repo.'/Settings', "Workspace\n");
    $this->get('/repository?repo=client&file='.hash('sha256', 'Settings'))
        ->assertOk()->assertSee('Rozdíly v souboru')->assertSee('Nesledované')->assertSee('Připravit soubor')
        ->assertSee('value="Untracked"', false)->assertSee('+Workspace')->assertSee('>Settings</', false);
});
