<?php

use App\Git\GitHubClient;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Native\Desktop\Facades\Shell;

it('opens the GitHub remote of a local project even when origin uses GitLab', function (): void {
    Storage::fake('local');
    $root = Storage::disk('local')->path('projects');
    Process::run(['git', 'init', $root.'/project'])->throw();
    Process::run(['git', '-C', $root.'/project', 'remote', 'add', 'origin', 'https://gitlab.com/example/old.git'])->throw();
    Storage::disk('local')->put('workspace.path', $root);

    $this->get('/cloud?local=project')->assertOk()->assertSee('no GitHub remote');
    Process::run(['git', '-C', $root.'/project', 'remote', 'add', 'github', 'git@github.com:example/private.git'])->throw();
    $this->get('/cloud?local=project')->assertOk()->assertSee('example/private')->assertSee('repository?repo=project', false);
    Process::run(['git', '-C', $root.'/project', 'remote', 'set-url', 'github', 'https://github.com/example/.github.git'])->throw();
    expect((new GitHubClient)->remote($root.'/project'))->toBe('example/.github');
    $this->get('/cloud?local=..')->assertNotFound();
});

it('loads paginated read-only data and shows the previous snapshot on return', function (): void {
    Process::fake(function (PendingProcess $process) {
        $command = $process->command;
        if ($command[1] === 'config') {
            return Process::result('example');
        }
        expect(array_slice($command, 1, 6))->toBe(['api', '--hostname', 'github.com', '--method', 'GET', '--paginate']);
        $endpoint = end($command);
        $data = match ($endpoint) {
            'user/repos?sort=pushed&per_page=100' => ['example/private'],
            'repos/example/private/branches?per_page=100' => ['dev', 'main'],
            'repos/example/private/pulls?state=open&sort=updated&per_page=100' => [[
                'number' => 7, 'title' => '<Review>', 'author' => 'sam', 'head' => 'sam:topic',
                'base' => 'dev', 'updated' => '2026-01-04T08:00:00Z', 'draft' => true,
            ]],
        };

        return Process::result(implode("\n", array_map(
            static fn (array $page): string => json_encode($page, JSON_THROW_ON_ERROR), array_chunk($data, 1)
        )));
    });
    $this->get('/cloud')->assertOk()->assertSee('GitHub repositories');
    $this->get('/cloud/data')->assertOk()->assertSee('example/private');
    $this->get('/cloud/data?repository=example/private')->assertOk()->assertSee('Open pull requests (1)')
        ->assertSee('<Review>')->assertDontSee('<Review>', false)->assertSee('sam:topic')->assertSee('Branches (2)')->assertSee('main');
    $this->get('/cloud?repository=example/private')->assertOk()->assertSee('Last updated')->assertSee('Draft');
});

it('shows an actionable GitHub error without exposing CLI output', function (string $error, int $code, int $status, string $message): void {
    Process::fake(fn (PendingProcess $process) => $process->command[1] === 'config'
        ? Process::result('example') : Process::result('', $error.' private diagnostics', $code));
    $this->get('/cloud/data?repository=example/private')->assertStatus($status)->assertSee($message)->assertDontSee('private diagnostics');
})->with([
    ['not found', 127, 401, 'Install GitHub CLI'],
    ['gh auth login', 4, 401, 'Sign in'],
    ['HTTP 404', 1, 403, 'cannot access'],
    ['rate limit', 1, 503, 'request limit'],
    ['network error', 1, 503, 'connection'],
]);

it('rejects invalid repositories and non-local requests before invoking GitHub', function (): void {
    Process::fake();
    $this->get('/cloud/data?repository=owner/..')->assertNotFound();
    $this->get('/cloud/data?repository=owner/repo%3Fstate=closed')->assertNotFound();
    $this->get('http://untrusted.example/cloud/data')->assertForbidden();
    Process::assertNothingRan();
});

it('opens the first PR patch with all its hunks and switches files within the same snapshot', function (): void {
    $requests = [];
    Process::fake(function (PendingProcess $process) use (&$requests) {
        $cmd = $process->command;
        if ($cmd[1] === 'config') {
            return Process::result('test-user');
        }
        expect(array_slice($cmd, 1, 5))->toBe(['api', '--hostname', 'github.com', '--method', 'GET']);
        $requests[] = $endpoint = end($cmd);
        $files = [
            ['filename' => 'src/one.txt', 'previous_filename' => 'old.txt', 'status' => 'renamed', 'additions' => 2,
                'deletions' => 2, 'limited' => false, 'patch' => "@@ -1 +1 @@\n-old\n+<first>\n@@ -90 +90 @@\n-last\n+end"],
            ['filename' => 'asset.png', 'previous_filename' => null, 'status' => 'added', 'additions' => 0,
                'deletions' => 0, 'limited' => false, 'patch' => ''],
        ];
        $data = match ($endpoint) {
            'repos/example/private/pulls/7' => [['title' => 'Review files', 'head' => 'feature', 'base' => 'dev', 'changed_files' => 31]],
            'repos/example/private/pulls/7/files?per_page=30&page=1' => $files,
            'repos/example/private/pulls/7/files?per_page=30&page=2' => [array_merge($files[0], ['patch' => '@@ -3 +3 @@', 'limited' => true])],
        };

        return Process::result(json_encode($data, JSON_THROW_ON_ERROR));
    });
    $url = '/cloud/pulls/7?repository=example/private';
    $this->get($url)->assertOk()->assertSee('Review files')->assertSee('Next')->assertSee('asset.png')
        ->assertSee('From old.txt')->assertSee('+<first>')->assertSee('+end')->assertDontSee('Patch headers')
        ->assertDontSee('<first>', false);
    $this->get($url.'&snapshot=1&file='.hash('sha256', 'asset.png'))->assertOk()
        ->assertSee('GitHub did not provide a text diff')->assertDontSee('+end');
    expect($requests)->toHaveCount(2);
    $this->get($url.'&page=2')->assertOk()->assertSee('Previous')->assertSee('Only the beginning')->assertDontSee('>Next<', false);
    $this->get($url.'&snapshot=1&file='.hash('sha256', 'gone'))->assertOk()->assertSee('no longer on this page');
});

it('handles missing patch data, empty pull requests and an unavailable GitHub account', function (): void {
    Process::fake(fn (PendingProcess $process) => match (true) {
        $process->command[1] === 'config' => Process::result('test-user'),
        str_contains(end($process->command), '/files?') => Process::result('[]'),
        str_ends_with(end($process->command), '/8') => Process::result('[{"title":"Empty","head":"a","base":"b","changed_files":0}]'),
        default => Process::result('', 'HTTP 404', 1),
    });
    $this->get('/cloud/pulls/8?repository=example/private')->assertOk()->assertSee('No changed files.');
    $this->get('/cloud/pulls/9?repository=example/private')->assertSee('cannot access');
    $this->get('/cloud/pulls/9?repository=../anything')->assertNotFound();
    $this->get('/cloud/pulls/9?repository=example/private&page=101')->assertNotFound();
    $this->get('http://untrusted.example/cloud/pulls/8?repository=example/private')->assertForbidden();
});

it('opens the selected pull request on GitHub in the system browser', function (): void {
    $shell = Shell::fake();
    $this->post('/cloud/pulls/4/open?repository=example/private')->assertNoContent();
    $shell->assertOpenedExternal('https://github.com/example/private/pull/4/files');
});
