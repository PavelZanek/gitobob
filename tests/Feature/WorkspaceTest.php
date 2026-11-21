<?php

use App\Git\RepositoryScanner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    config(['nativephp-internal.api_url' => 'http://desktop.test/']);
});

afterEach(function (): void {
    File::deleteDirectory(Storage::disk('local')->path(''));
});

it('remembers the chosen folder and shows nested and linked working trees', function (): void {
    $projects = Storage::disk('local')->path('projects');
    $app = $projects.'/personal/Dvě slova';
    File::ensureDirectoryExists($app);
    Process::run(['git', 'init', $projects])->throw();
    Process::run(['git', 'init', $app])->throw();
    Process::run(['git', '-C', $app, '-c', 'user.name=Test', '-c', 'user.email=test@example.org', 'commit', '--allow-empty', '-m', 'Initial'])->throw();
    Process::run(['git', '-C', $app, 'worktree', 'add', '--detach', $projects.'/linked'])->throw();
    File::ensureDirectoryExists($projects.'/unrelated/.git');
    symlink($projects, $app.'/cycle');

    Http::fake(['desktop.test/dialog/open' => Http::response(['result' => [$projects]])]);

    $this->post('/workspace')->assertRedirect('/');
    $this->get('/')->assertOk()->assertSeeInOrder(['projects', 'linked', 'personal', 'Dvě slova'])
        ->assertSee('Detached HEAD')->assertDontSee('unrelated')->assertDontSee('cycle');
    expect(Storage::disk('local')->get('workspace.path'))->toBe($projects);
});

it('keeps the previous folder when the picker is dismissed', function (): void {
    Storage::disk('local')->put('workspace.path', '/previous');
    Http::fake(['desktop.test/dialog/open' => Http::response(['result' => null])]);

    $this->post('/workspace')->assertRedirect('/');
    expect(Storage::disk('local')->get('workspace.path'))->toBe('/previous');
    $this->get('/')->assertOk()->assertSee('Folder unavailable');
});

it('shows the empty repository state and ignores dependency directories', function (): void {
    $root = Storage::disk('local')->path('empty');
    File::ensureDirectoryExists($root.'/vendor');
    Process::run(['git', 'init', $root.'/vendor/package'])->throw();
    Storage::disk('local')->put('workspace.path', $root);

    $this->get('/')->assertOk()->assertSee('No repositories found')->assertDontSee('package');
    expect((new RepositoryScanner)->scan($root.'/missing'))->toBeNull();
});

it('shows branch and file counts and keeps only the paths to changed repositories', function (): void {
    $root = Storage::disk('local')->path('workspace');
    $project = $root.'/group/editor';
    File::ensureDirectoryExists($project);
    Process::run(['git', 'init', '-b', 'workspace-branch', $root])->throw();
    Process::run(['git', 'init', '-b', 'feature/editor', $project])->throw();
    Process::run(['git', 'init', '-b', 'clean-branch', $root.'/clean-sibling'])->throw();
    file_put_contents($root.'/.git/info/exclude', "/group/\n/clean-sibling/\n");
    Storage::disk('local')->put('workspace.path', $root);

    $this->get('/')->assertSee('workspace-branch')->assertSee('feature/editor')
        ->assertSee('clean-branch')->assertSee('0 changed files');
    $this->get('/?changed=1')->assertSee('All repositories are clean.')->assertSee('Only changed');

    file_put_contents($project.'/before.txt', 'original');
    Process::run(['git', '-C', $project, 'add', 'before.txt'])->throw();
    Process::run(['git', '-C', $project, '-c', 'user.name=Test', '-c', 'user.email=test@example.org', 'commit', '-m', 'Initial'])->throw();
    Process::run(['git', '-C', $project, 'mv', 'before.txt', 'after.txt'])->throw();
    file_put_contents($project.'/after.txt', 'unstaged edit');
    $this->get('/?changed=1')->assertSee('1 changed file');

    File::ensureDirectoryExists($project.'/new files');
    file_put_contents($project."/new files/one\ntwo", 'new');
    file_put_contents($project.'/new files/three', 'new');
    file_put_contents($project.'/.git/info/exclude', "/ignored.txt\n");
    file_put_contents($project.'/ignored.txt', 'ignored');

    $this->get('/?changed=1')->assertOk()
        ->assertSeeInOrder(['workspace</span>', 'group</span>', 'editor</span>', 'feature/editor', '3 changed files'], false)
        ->assertDontSee('workspace-branch')->assertDontSee('clean-sibling')->assertDontSee('0 changed files');
    $this->get('/')->assertSee('clean-sibling')->assertSee('3 changed files');
});
