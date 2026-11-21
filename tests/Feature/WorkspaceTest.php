<?php

use App\Git\RepositoryScanner;
use Illuminate\Process\PendingProcess;
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

    $this->post('/workspace')->assertRedirect('/?opening=1');
    $opening = $this->get('/?opening=1')->assertOk()->assertSee('Finding repositories…')->assertDontSee('No repositories found');
    $token = $opening->viewData('token');
    expect($token)->toBeString();
    do {
        $page = $this->get('/?batch='.$token)->assertOk();
    } while ($page->viewData('token') !== null);
    $this->get('/?batch='.$opening->viewData('token'))->assertOk()->assertSee('Detached HEAD');
    $page->assertSeeInOrder(['projects', 'linked', 'personal', 'Dvě slova'])
        ->assertSee('Detached HEAD')->assertDontSee('unrelated')->assertDontSee('cycle');
    expect(Storage::disk('local')->get('workspace.path'))->toBe($projects);
});

it('keeps the previous folder when the picker is dismissed', function (): void {
    Storage::disk('local')->put('workspace.path', '/previous');
    Http::fake(['desktop.test/dialog/open' => Http::response(['result' => null])]);

    $this->post('/workspace')->assertRedirect('/?snapshot=1');
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

it('reuses a completed scan when reopening and isolates in-flight scans by workspace', function (): void {
    $first = Storage::disk('local')->path('first');
    $other = Storage::disk('local')->path('second');
    Process::run(['git', 'init', $first.'/plugins/one'])->throw();
    Process::run(['git', 'init', $other.'/two'])->throw();
    Storage::disk('local')->put('workspace.path', $first);
    $token = $this->get('/?opening=1')->viewData('token');
    $this->get('/?opening=1')->assertViewHas('token', $token);
    Storage::disk('local')->put('workspace.path', $other);
    $this->get('/?batch='.$token)->assertConflict();
    $this->get('/')->assertSee('two');
    $this->get('/?opening=1')->assertSee('two')->assertDontSee('Finding repositories…');
    Storage::disk('local')->put('workspace.path', $first);
    $this->get('/?batch='.$token)->assertSee('one')->assertDontSee('two');
});

it('discovers deeply nested repositories in a wide workspace and skips dependencies and directory links', function (): void {
    $root = Storage::disk('local')->path('wide');
    foreach (range(1, 20) as $index) {
        File::ensureDirectoryExists($root.'/projects/plugins/group-'.$index);
    }
    $group = $root.'/projects/plugins/group-2';
    Process::run(['git', 'init', '-b', 'deep-review', $group.'/nested space/app'])->throw();
    Process::run(['git', 'init', '-b', 'dependency-only', $group.'/vendor/dependency'])->throw();
    symlink($root, $group.'/cycle');
    Storage::disk('local')->put('workspace.path', $root);
    $page = $this->get('/?opening=1')->assertOk();
    while ($token = $page->viewData('token')) {
        $page = $this->get('/?batch='.$token)->assertOk();
    }
    $page->assertSee('deep-review')->assertDontSee('dependency-only')->assertDontSee('cycle');
});

it('applies the saved search depth to the workspace tree and all changes', function (): void {
    $root = Storage::disk('local')->path('bounded');
    $nested = $root.'/one/two/three/four/five';
    Process::run(['git', 'init', '-b', 'root-branch', $root])->throw();
    Process::run(['git', 'init', '-b', 'fifth-level', $nested])->throw();
    Process::run(['git', 'init', '-b', 'sixth-level', $nested.'/six'])->throw();
    file_put_contents($nested.'/six/note.md', 'Deep change');
    Storage::disk('local')->put('workspace.path', $root);

    $this->get('/settings')->assertSee('value="5"', false)->assertSee('data-theme="system"', false);
    $this->get('/changes')->assertDontSee('Deep change');
    $this->get('/?snapshot=1')->assertSee('fifth-level')->assertDontSee('sixth-level');
    $this->post('/settings', ['theme' => 'dark', 'depth' => 0])->assertRedirect('/settings');
    $this->get('/?snapshot=1')->assertSee('data-theme="dark"', false)->assertSee('root-branch')->assertDontSee('fifth-level');
    $this->post('/settings', ['theme' => 'light', 'depth' => ''])->assertRedirect('/settings');
    $this->get('/changes?snapshot=1')->assertSee('Deep change')->assertSee('data-theme="light"', false);
    $this->get('/?snapshot=1')->assertSee('sixth-level');
    $this->post('/settings', ['theme' => 'unknown', 'depth' => -1])->assertSessionHasErrors(['theme', 'depth']);
    $this->get('/settings')->assertSee('data-theme="light"', false);
    $this->post('/settings', ['theme' => 'system', 'depth' => 5])->assertRedirect('/settings');
    $this->get('/settings')->assertSee('data-theme="system"', false);
});

it('discovers repositories in their final visible order across scan batches', function (): void {
    $root = Storage::disk('local')->path('ordered');
    foreach (['zulu', 'alpha/z-last', 'alpha', 'alpha/a-first'] as $relative) {
        File::ensureDirectoryExists($root.'/'.$relative.'/.git');
    }
    $read = [];
    Process::fake(function (PendingProcess $git) use (&$read, $root) {
        $read[] = substr($git->path, strlen($root) + 1);

        return Process::result(output: "# branch.head dev\0? note.md\0");
    });
    Storage::disk('local')->put('workspace.path', $root);
    $page = $this->get('/?opening=1')->assertSee('All changes')->assertSee('aria-disabled="true"', false);
    $order = ['alpha', 'alpha/a-first', 'alpha/z-last', 'zulu'];
    while ($token = $page->viewData('token')) {
        $page = $this->get('/?batch='.$token)->assertOk();
        expect($read)->toBe(array_slice($order, 0, count($read)));
    }
    $page->assertSeeInOrder(['alpha</span>', 'a-first</span>', 'z-last</span>', 'zulu</span>'], false)->assertSee('4 found');
    $this->get('/?snapshot=1')->assertSee('4 found');
    expect($read)->toBe($order);
});
