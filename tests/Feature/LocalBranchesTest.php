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
