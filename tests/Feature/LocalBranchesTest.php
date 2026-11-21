<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    $this->root = sys_get_temp_dir().'/gitobob-branches-'.Str::uuid();
    $this->repo = $this->root.'/demo';
    File::ensureDirectoryExists($this->repo);
    $this->git = fn (array $args): string => trim(Process::path($this->repo)->run(['git', ...$args])->throw()->output());
    ($this->git)(['init', '-b', 'dev']);
    ($this->git)(['config', 'user.name', 'Test']);
    ($this->git)(['config', 'user.email', 'test@example.org']);
    file_put_contents($this->repo.'/guide.md', "Original\n");
    ($this->git)(['add', '.']);
    ($this->git)(['commit', '-m', 'Original guide']);
    ($this->git)(['switch', '-c', 'alternate']);
    file_put_contents($this->repo.'/guide.md', "Alternate\n");
    ($this->git)(['commit', '-am', 'Alternate guide']);
    ($this->git)(['switch', 'dev']);
    Storage::disk('local')->put('workspace.path', $this->root);
    $this->url = '/repository/branches?repo=demo';
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

it('switches clean branches and returns to the updated repository', function (): void {
    $page = $this->get($this->url)->assertOk()->assertSee('Switch to alternate');
    $this->followingRedirects()->post($this->url, ['operation' => 'switch', 'branch' => 'alternate', 'version' => $page->viewData('version')])
        ->assertOk()->assertSee('Switched to alternate.')->assertSee('This repository is clean.');
    expect(file_get_contents($this->repo.'/guide.md'))->toBe("Alternate\n");
});

it('keeps both layers of edits when switching is blocked and when creating a branch', function (): void {
    file_put_contents($this->repo.'/guide.md', "Staged\n");
    ($this->git)(['add', 'guide.md']);
    file_put_contents($this->repo.'/guide.md', "Working\n");
    $version = $this->get($this->url)->viewData('version');
    $this->followingRedirects()->post($this->url, ['operation' => 'switch', 'branch' => 'alternate', 'version' => $version])
        ->assertSee('would be overwritten')->assertSee('guide.md');
    expect(($this->git)(['branch', '--show-current']))->toBe('dev');
    $this->followingRedirects()->post($this->url, ['operation' => 'create', 'branch' => 'feature/guide', 'version' => $version])
        ->assertSee('Created and switched to feature/guide.')->assertSee('+Working')->assertSee('+Staged');
    expect(($this->git)(['branch', '--show-current']))->toBe('feature/guide')
        ->and(($this->git)(['show', ':guide.md']))->toBe('Staged')
        ->and(file_get_contents($this->repo.'/guide.md'))->toBe("Working\n")
        ->and(($this->git)(['rev-parse', 'HEAD']))->toBe(($this->git)(['rev-parse', 'dev']));
});

it('refuses stale selections, duplicate names and Git revision expressions', function (): void {
    $version = $this->get($this->url)->viewData('version');
    foreach (['alternate' => 'already exists', '@{-1}' => 'valid Git branch name', '-f' => 'valid Git branch name'] as $name => $message) {
        $this->followingRedirects()->post($this->url, ['operation' => 'create', 'branch' => $name, 'version' => $version])->assertSee($message);
    }
    ($this->git)(['switch', 'alternate']);
    $this->followingRedirects()->post($this->url, ['operation' => 'create', 'branch' => 'stale', 'version' => $version])->assertSee('branches changed');
    expect(($this->git)(['branch', '--list', 'stale']))->toBe('');
});

it('reviews the blocked switch, saves the selected files and restores both layers without consuming the stash', function (bool $includeNew): void {
    file_put_contents($this->repo.'/guide.md', "Staged edit\n");
    ($this->git)(['add', 'guide.md']);
    file_put_contents($this->repo.'/guide.md', "Working edit\n");
    file_put_contents($this->repo.'/new.md', "New note\n");
    $version = $this->get($this->url)->viewData('version');
    $this->followingRedirects()->post($this->url, ['operation' => 'switch', 'branch' => 'alternate', 'version' => $version])->assertSee('Stash and switch…');
    $page = $this->get('/repository/stash-switch?repo=demo&target=alternate')->assertOk()->assertSee('guide.md')->assertSee('Include untracked files');
    $this->followingRedirects()->post('/repository/stash-switch?repo=demo', ['target' => 'alternate', 'version' => $page->viewData('version'), 'note' => 'Review edits', 'untracked' => $includeNew])
        ->assertSee('Switched to alternate.');
    expect(file_exists($this->repo.'/new.md'))->toBe(! $includeNew);
    ($this->git)(['switch', 'dev']);
    $list = '/repository/stashes?repo=demo';
    $stash = $this->get($list)->assertOk()->assertSee('On dev: Review edits')->assertSee('+Staged edit')->assertSee('+Working edit');
    if ($includeNew) {
        $this->get($list.'&file='.hash('sha256', 'new.md'))->assertSee('Untracked')->assertSee('+New note');
    } else {
        $stash->assertDontSee('new.md');
    }
    $apply = ['stash' => $stash->viewData('oid'), 'version' => $stash->viewData('version')];
    $this->followingRedirects()->post('/repository/stashes/apply?repo=demo', $apply)->assertSee('Applied stash');
    expect(($this->git)(['show', ':guide.md']))->toBe('Staged edit')
        ->and(file_get_contents($this->repo.'/guide.md'))->toBe("Working edit\n")
        ->and(file_get_contents($this->repo.'/new.md'))->toBe("New note\n")
        ->and(($this->git)(['rev-parse', 'refs/stash']))->toBe($apply['stash']);
    $this->followingRedirects()->post('/repository/stashes/apply?repo=demo', $apply)->assertSee('changed. Review them');
})->with([true, false]);

it('refuses stale stash previews and reports a conflicting apply with the saved copy intact', function (): void {
    file_put_contents($this->repo.'/guide.md', "Changed\n");
    $url = '/repository/stash-switch?repo=demo';
    $version = $this->get($url.'&target=alternate')->viewData('version');
    file_put_contents($this->repo.'/guide.md', "Local title\n");
    $this->followingRedirects()->post($url, ['target' => 'alternate', 'version' => $version])->assertSee('files changed');
    expect(($this->git)(['branch', '--show-current']))->toBe('dev')->and(($this->git)(['stash', 'list']))->toBe('');
    $version = $this->get($url.'&target=alternate')->viewData('version');
    $this->post($url, ['target' => 'alternate', 'version' => $version])->assertRedirect();
    $stash = $this->get('/repository/stashes?repo=demo');
    $this->followingRedirects()->post('/repository/stashes/apply?repo=demo', ['stash' => $stash->viewData('oid'), 'version' => $stash->viewData('version')])
        ->assertSee('Conflicted files:')->assertSee('guide.md')->assertSee('The stash is still saved');
    expect(($this->git)(['diff', '--name-only', '--diff-filter=U']))->toBe('guide.md')
        ->and(($this->git)(['rev-parse', 'refs/stash']))->toBe($stash->viewData('oid'));
});
