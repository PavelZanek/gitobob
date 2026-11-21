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
    $this->get('/repository?repo=demo')->assertSee('Sync…');
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
    $this->get($this->url)->assertSee('Publish branch')->assertSee('origin/dev');
    $this->post('/repository/push?repo=..', [])->assertNotFound();
});

it('fetches only the tracked branch, compares unique commits and fast-forwards only a reviewed behind branch', function (): void {
    $head = ($this->git)(['rev-parse', 'HEAD']);
    ($this->git)(['clone', '-b', 'review', '../remote.git', '../writer']);
    file_put_contents($this->root.'/writer/incoming.md', "From the remote\n");
    ($this->git)(['-C', '../writer', 'add', 'incoming.md']);
    ($this->git)(['-C', '../writer', '-c', 'user.name=Test', '-c', 'user.email=test@example.org', 'commit', '-m', 'Remote guide']);
    $draft = ($this->git)(['-C', '../writer', 'rev-parse', 'HEAD']);
    file_put_contents($this->root.'/writer/incoming.md', "Ready to pull\n");
    file_put_contents($this->root.'/writer/notes.md', "Check both files\n");
    ($this->git)(['-C', '../writer', 'add', '.']);
    ($this->git)(['-C', '../writer', '-c', 'user.name=Test', '-c', 'user.email=test@example.org', 'commit', '-m', 'Finish guide']);
    $remoteHead = ($this->git)(['-C', '../writer', 'rev-parse', 'HEAD']);
    $version = $this->get($this->url)->viewData('version');
    ($this->git)(['-C', '../writer', 'push', 'origin', 'review']);
    ($this->git)(['-C', '../writer', 'push', 'origin', 'HEAD:refs/heads/other']);
    $page = $this->followingRedirects()->post($this->url, ['version' => $version])
        ->assertSee('Push stopped.')->assertSee('Fetch to compare commits.');
    $fetch = '/repository/fetch?repo=demo';
    $pull = '/repository/pull?repo=demo';
    $page = $this->followingRedirects()->post($fetch, ['version' => $page->viewData('version')])
        ->assertSee('Fetched origin/review.')->assertSee('branches have diverged')->assertDontSee('Pull (fast-forward)')
        ->assertSeeInOrder(['Local only', 'Review outgoing change', 'Remote only', 'Remote guide']);
    $comparison = $this->url.'&review='.$page->viewData('version');
    $this->get($comparison.'&commit='.$head)->assertOk()->assertSee('No files changed in this comparison.');
    $this->get($comparison.'&commit='.$draft)->assertOk()->assertSee('+From the remote');
    $this->get($comparison.'&commit='.$remoteHead)->assertOk()->assertSee('-From the remote')->assertSee('+Ready to pull');
    $this->get($comparison.'&commit='.$this->base)->assertNotFound();
    $this->post($pull, ['version' => $page->viewData('version')])->assertSessionHas('notice', 'Pull stopped. Review the updated branch comparison.');
    $this->post($this->url, ['version' => $page->viewData('version')])->assertSessionHas('notice', 'Push stopped. The branch or destination changed since your preview.');
    expect(($this->git)(['rev-parse', 'HEAD']))->toBe($head)
        ->and(($this->git)(['rev-parse', 'origin/review']))->toBe($remoteHead)
        ->and(($this->git)(['branch', '-r', '--list', 'origin/other']))->toBe('')
        ->and(file_exists($this->repo.'/incoming.md'))->toBeFalse();

    ($this->git)(['switch', '-c', 'behind', $this->base]);
    ($this->git)(['branch', '--set-upstream-to=origin/review']);
    $version = $this->get($this->url)->assertSee('0 outgoing commits')->assertSee('2 incoming commits')->assertSee('+Ready to pull')->assertDontSee('From the remote')
        ->assertSee('Pull (fast-forward)')->viewData('version');
    $this->get($this->url.'&review='.$version.'&file='.hash('sha256', 'notes.md'))->assertOk()->assertSee('+Check both files')->assertDontSee('+Ready to pull');
    file_put_contents($this->repo.'/incoming.md', "Local copy\n");
    $this->followingRedirects()->post($pull, ['version' => $version])->assertSee('Git could not fast-forward');
    expect(file_get_contents($this->repo.'/incoming.md'))->toBe("Local copy\n")->and(($this->git)(['rev-parse', 'HEAD']))->toBe($this->base);
    rename($this->repo.'/incoming.md', $this->repo.'/unrelated.md');
    $this->followingRedirects()->post($pull, ['version' => $version])->assertSee('Fast-forwarded behind')->assertSee('Up to date');
    expect(($this->git)(['rev-parse', 'HEAD']))->toBe($remoteHead)
        ->and(file_get_contents($this->repo.'/incoming.md'))->toBe("Ready to pull\n")
        ->and(file_get_contents($this->repo.'/unrelated.md'))->toBe("Local copy\n");
});

it('refuses an outdated pull when the remote advances again', function (): void {
    ($this->git)(['branch', 'behind', $this->base]);
    ($this->git)(['push', 'origin', 'dev:review']);
    ($this->git)(['switch', 'behind']);
    ($this->git)(['branch', '--set-upstream-to=origin/review']);
    $version = $this->get($this->url)->assertSee('Pull (fast-forward)')->viewData('version');
    $next = ($this->git)(['commit-tree', 'dev^{tree}', '-p', 'dev', '-m', 'Another remote commit']);
    ($this->git)(['push', 'origin', $next.':refs/heads/review']);
    $this->followingRedirects()->post('/repository/pull?repo=demo', ['version' => $version])->assertSee('Pull stopped.');
    expect(($this->git)(['rev-parse', 'HEAD']))->toBe($this->base);
});

it('compares the advertised branch tips when fetch and push use different URLs', function (): void {
    ($this->git)(['config', 'remote.origin.pushurl', $this->root.'/remote.git']);
    $page = $this->get($this->url)->assertSee('>Fetch<', false);
    $this->followingRedirects()->post('/repository/fetch?repo=demo', ['version' => $page->viewData('version')])
        ->assertSee('Fetched origin/review.');
    ($this->git)(['init', '--bare', '../fork.git']);
    ($this->git)(['push', '../fork.git', 'HEAD:refs/heads/review']);
    ($this->git)(['config', 'remote.origin.pushurl', '../fork.git']);
    $this->get($this->url)->assertDontSee('>Fetch<', false)->assertDontSee('Pull (fast-forward)');
});

it('publishes the reviewed commit and tracks the selected remote for subsequent sync', function (): void {
    ($this->git)(['switch', '--no-track', '-c', 'feature/guide']);
    ($this->git)(['init', '--bare', '../second.git']);
    ($this->git)(['remote', 'add', 'second', '../second.git']);
    ($this->git)(['config', 'remote.second.fetch', '+refs/heads/dev:refs/remotes/second/dev']);
    $page = $this->get($this->url.'&remote=second')->assertOk()->assertSee('second/feature/guide')->assertSee('Publish branch');
    $this->followingRedirects()->post('/repository/publish?repo=demo', ['version' => $page->viewData('version'), 'remote' => 'second'])
        ->assertSee('Published second/feature/guide')->assertSee('Up to date')->assertDontSee('Publish branch');
    expect(($this->git)(['rev-parse', '--abbrev-ref', '@{upstream}']))->toBe('second/feature/guide')
        ->and(($this->git)(['--git-dir=../second.git', 'rev-parse', 'feature/guide']))->toBe(($this->git)(['rev-parse', 'HEAD']))
        ->and(($this->git)(['--git-dir=../remote.git', 'branch', '--list', 'feature/guide']))->toBe('');
});

it('refuses publication when the local tip moves or someone creates the destination', function (): void {
    ($this->git)(['switch', '--no-track', '-c', 'feature/new']);
    $publish = '/repository/publish?repo=demo';
    $version = $this->get($this->url)->viewData('version');
    ($this->git)(['commit', '--allow-empty', '-m', 'Later change']);
    $this->followingRedirects()->post($publish, ['version' => $version])->assertSee('Publish stopped');
    $version = $this->get($this->url)->viewData('version');
    ($this->git)(['push', 'origin', $this->base.':refs/heads/feature/new']);
    $this->followingRedirects()->post($publish, ['version' => $version])->assertSee('already exists on the remote')->assertDontSee('Publish branch');
    expect(($this->git)(['--git-dir=../remote.git', 'rev-parse', 'feature/new']))->toBe($this->base);
    ($this->git)(['switch', '--no-track', '-c', 'main']);
    $this->get($this->url)->assertSee('destination is protected')->assertDontSee('Publish branch');
});
