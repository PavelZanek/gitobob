<?php

use App\Git\GitHubClient;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

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
