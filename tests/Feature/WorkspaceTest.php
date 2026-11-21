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
        ->assertDontSee('unrelated')->assertDontSee('cycle');
    expect(Storage::disk('local')->get('workspace.path'))->toBe($projects);
});

it('keeps the previous folder when the picker is dismissed', function (): void {
    Storage::disk('local')->put('workspace.path', '/previous');
    Http::fake(['desktop.test/dialog/open' => Http::response(['result' => null])]);

    $this->post('/workspace')->assertRedirect('/');
    expect(Storage::disk('local')->get('workspace.path'))->toBe('/previous');
    $this->get('/')->assertOk()->assertSee('Složka není dostupná');
});

it('shows the empty repository state and ignores dependency directories', function (): void {
    $root = Storage::disk('local')->path('empty');
    File::ensureDirectoryExists($root.'/vendor');
    Process::run(['git', 'init', $root.'/vendor/package'])->throw();
    Storage::disk('local')->put('workspace.path', $root);

    $this->get('/')->assertOk()->assertSee('Žádné repozitáře')->assertDontSee('package');
    expect((new RepositoryScanner)->scan($root.'/missing'))->toBeNull();
});
