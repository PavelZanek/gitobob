<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    $this->root = sys_get_temp_dir().'/gitobob-push-'.Str::uuid();
    $this->repo = $this->root.'/demo';
    File::ensureDirectoryExists($this->repo);
    $this->git = fn (array $args): string => trim(Process::path($this->repo)->run(['git', ...$args])->throw()->output());
    ($this->git)(['init', '-b', 'dev']);
    ($this->git)(['config', 'user.name', 'Test']);
    ($this->git)(['config', 'user.email', 'test@example.org']);
    ($this->git)(['commit', '--allow-empty', '-m', 'Start']);
    ($this->git)(['init', '--bare', '../remote.git']);
    ($this->git)(['remote', 'add', 'origin', '../remote.git']);
    ($this->git)(['push', '-u', 'origin', 'dev:review']);
    $this->base = ($this->git)(['rev-parse', 'HEAD']);
    ($this->git)(['commit', '--allow-empty', '-m', 'Review outgoing change']);
    $this->url = '/repository/push?repo=demo';
    Storage::disk('local')->put('workspace.path', $this->root);
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

it('pushes the approved commits to the named upstream and shows the synchronized state', function (): void {
    file_put_contents($this->repo.'/local.md', 'Only here');
    $this->get('/repository?repo=demo')->assertSee('Push commits…');
    $page = $this->get($this->url)->assertSee('origin/review')->assertSee('1 outgoing commit')->assertSee('Review outgoing change');
    $head = ($this->git)(['rev-parse', 'HEAD']);
    $this->followingRedirects()->post($this->url, ['version' => $page->viewData('version')])
        ->assertSee('Pushed '.substr($head, 0, 8))->assertSee('Up to date')->assertDontSee('Push to origin/review');
    expect(($this->git)(['--git-dir=../remote.git', 'rev-parse', 'review']))->toBe($head)
        ->and(($this->git)(['status', '--porcelain']))->toBe('?? local.md');
});

it('refuses a changed preview and preserves both branch tips', function (bool $remoteMoves): void {
    $version = $this->get($this->url)->viewData('version');
    if ($remoteMoves) {
        $tree = ($this->git)(['rev-parse', 'HEAD^{tree}']);
        $new = ($this->git)(['commit-tree', $tree, '-p', $this->base, '-m', 'Remote work']);
        ($this->git)(['push', 'origin', $new.':refs/heads/review']);
    } else {
        ($this->git)(['commit', '--allow-empty', '-m', 'New local work']);
    }
    $head = ($this->git)(['rev-parse', 'HEAD']);
    $this->followingRedirects()->post($this->url, ['version' => $version])->assertSee('Push stopped.');
    expect(($this->git)(['--git-dir=../remote.git', 'rev-parse', 'review']))->toBe($new ?? $this->base)
        ->and(($this->git)(['rev-parse', 'HEAD']))->toBe($head);
})->with([true, false]);

it('honours pre-push hooks', function (): void {
    $script = $this->repo.'/.git/hooks/pre-push';
    file_put_contents($script, "#!/bin/sh\nexit 1\n");
    chmod($script, 0755);
    $version = $this->get($this->url)->viewData('version');
    $this->followingRedirects()->post($this->url, ['version' => $version])->assertSee('Git rejected the push.');
    expect(($this->git)(['--git-dir=../remote.git', 'rev-parse', 'review']))->toBe($this->base);
});

it('requires a tracked and permitted destination', function (): void {
    ($this->git)(['config', 'branch.dev.merge', 'refs/heads/main']);
    $this->get($this->url)->assertSee('This destination is protected')->assertDontSee('<form', false);
    ($this->git)(['branch', '--unset-upstream']);
    $this->get($this->url)->assertSee('Set an upstream branch')->assertDontSee('<form', false);
    $this->post('/repository/push?repo=..', [])->assertNotFound();
});
