<?php

use App\Git\GitHubClient;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Native\Desktop\Facades\Shell;

beforeEach(function (): void {
    Storage::fake('local');
});

it('opens the GitHub remote of a local project even when origin uses GitLab', function (): void {
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

it('navigates both ways across pages and filters paths across the entire pull request', function (): void {
    $files = [];
    foreach (range(1, 34) as $i) {
        $path = $i % 2 === 0 ? 'tests' : 'src';
        $files[] = ['filename' => "$path/file$i.php", 'previous_filename' => null, 'sha' => "blob$i", 'status' => 'added',
            'additions' => 1, 'deletions' => 0, 'limited' => false, 'patch' => "+line$i"];
    }
    Process::fake(function (PendingProcess $process) use ($files) {
        if ($process->command[1] === 'config') {
            return Process::result('reviewer');
        }
        $data = match (end($process->command)) {
            'repos/sample/review/pulls/4' => [['title' => 'Multi-page PR', 'head' => 'topic', 'base' => 'dev', 'changed_files' => 34]],
            'repos/sample/review/pulls/4/files?per_page=100&page=1' => $files,
            'repos/sample/review/pulls/4/files?per_page=30&page=1' => array_slice($files, 0, 30),
            'repos/sample/review/pulls/4/files?per_page=30&page=2' => array_slice($files, 30),
        };

        return Process::result(json_encode($data, JSON_THROW_ON_ERROR));
    });
    $url = '/cloud/pulls/4?repository=sample/review';
    $this->get($url.'&edge=last')->assertOk()->assertSee('File 30 of 34')->assertSee('+line30');
    $this->get($url.'&locate=1&file='.hash('sha256', 'tests/file34.php'))->assertSee('File 34 of 34')->assertSee('+line34');
    $this->get($url.'&locate=1&file='.hash('sha256', 'removed.md'))->assertSee('File 1 of 34')->assertSee('+line1');
    $this->get($url.'&page=2')->assertSee('File 31 of 34')->assertSee('+line31')->assertSee('edge=last');
    $this->get($url.'&page=2&edge=last')->assertSee('File 34 of 34')->assertDontSee('data-step="j"', false);
    $this->get($url.'&filter=TESTS%2F&edge=last')->assertOk()->assertSee('File 17 of 17')->assertSee('+line34')->assertDontSee('file31.php');
    $this->get($url.'&resume=1')->assertSee('File 34 of 34')->assertSee('+line34');
    $this->get($url.'&filter=missing')->assertOk()->assertSee('No files match your filters.');
});

it('persists viewed versions per account and makes edited files unread again', function (): void {
    $login = 'reader';
    $sha = 'old-blob';
    Process::fake(function (PendingProcess $process) use (&$login, &$sha) {
        if ($process->command[1] === 'config') {
            return Process::result($login);
        }
        $data = str_contains(end($process->command), '/files?')
            ? [['filename' => 'logo.png', 'previous_filename' => null, 'sha' => $sha, 'status' => 'modified',
                'additions' => 0, 'deletions' => 0, 'limited' => false, 'patch' => '']]
            : [['title' => 'Update logo', 'head' => 'topic', 'base' => 'dev', 'changed_files' => 1]];

        return Process::result(json_encode($data, JSON_THROW_ON_ERROR));
    });

    $url = '/cloud/pulls/4?repository=sample/review';
    $mark = '/cloud/pulls/4/viewed?repository=sample/review';
    $page = $this->get($url)->assertSee('Mark as viewed');
    $payload = ['scope' => hash('sha256', $page->viewData('scope')), 'id' => $page->viewData('selected')['id'],
        'version' => $page->viewData('selected')['version'], 'viewed' => 1];
    $this->post($mark, $payload)->assertRedirect();
    $this->get($url)->assertOk()->assertSee('✓ Viewed');
    $this->get($url.'&hide=1')->assertSee('No files match your filters.');
    $this->post($mark, ['viewed' => 0] + $payload)->assertRedirect();
    $this->get($url.'&hide=1')->assertSee('Mark as viewed');
    $this->post($mark, $payload);
    $login = 'another-reader';
    $this->get($url)->assertSee('Mark as viewed');
    $this->post($mark, $payload)->assertConflict();
    $login = 'reader';
    $sha = 'changed-blob';
    $this->get($url.'&hide=1')->assertOk()->assertSee('logo.png')->assertSee('Mark as viewed');
});

it('resumes the actual review and reconciles local progress after the pull request changes', function (): void {
    $account = 'alex';
    $sha = 'original';
    $requests = [];
    Process::fake(function (PendingProcess $process) use (&$account, &$sha, &$requests) {
        if ($process->command[1] === 'config') {
            return Process::result($account);
        }
        $requests[] = $endpoint = end($process->command);
        $pr = ['number' => 7, 'title' => 'Example PR', 'author' => 'alex', 'head' => 'topic', 'base' => 'main',
            'updated' => '2026-01-04T00:00:00Z', 'draft' => false, 'changed_files' => 3, 'base_sha' => 'base', 'head_sha' => $sha];
        $data = match ($endpoint) {
            'repos/example/repo/pulls/7', 'repos/example/repo/pulls?state=open&sort=updated&per_page=100' => [$pr],
            'repos/example/repo/branches?per_page=100' => ['main'],
            'repos/example/repo/pulls/7/files?per_page=30&page=2' => [],
            'repos/example/repo/pulls/7/files?per_page=30&page=1' => array_map(static fn (string $name): array => [
                'filename' => $name, 'previous_filename' => null, 'sha' => $name === 'a' ? $sha : $name, 'status' => 'modified',
                'additions' => 1, 'deletions' => 1, 'limited' => false, 'patch' => "@@ -1 +1 @@\n-old\n+$name",
            ], ['a', 'b', 'c']),
        };

        return Process::result(json_encode($data, JSON_THROW_ON_ERROR));
    });
    $url = '/cloud/pulls/7?repository=example/repo';
    $overview = '/cloud/data?repository=example/repo';
    $this->get($url, ['X-GitoBob-Preview' => '1'])->assertOk();
    $this->get($overview)->assertDontSee('Continue review');

    $mark = function (string $name) use ($url): void {
        $page = $this->get($url.'&file='.hash('sha256', $name))->assertOk();
        $selected = $page->viewData('selected');
        $this->post('/cloud/pulls/7/viewed?repository=example/repo', [
            'scope' => hash('sha256', $page->viewData('scope')), 'id' => $selected['id'], 'version' => $selected['version'], 'viewed' => 1,
        ])->assertRedirect();
    };
    $mark('a');
    $this->get($url.'&page=2')->assertOk();
    $this->get($url.'&file='.hash('sha256', 'b'));
    $this->get($url, ['X-GitoBob-Preview' => '1']);
    $requests = [];
    $this->get('/cloud?repository=example/repo')->assertOk()->assertSee('1 of 3 files viewed')->assertSee('Continue review');
    expect($requests)->toBe([]);
    $this->get($url.'&resume=1')->assertSee('File 2 of 3')->assertSee('+b');
    $mark('b');
    $this->get($url.'&resume=1')->assertSee('File 3 of 3')->assertSee('+c');
    $page = $this->get($url)->assertSee('File 1 of 3');
    $headers = ['X-GitoBob-Revision' => hash('sha256', $page->viewData('scope').$page->viewData('revision'))];
    $requests = [];
    $this->get($url, $headers)->assertNoContent();
    expect($requests)->toBe(['repos/example/repo/pulls/7']);
    $sha = 'new-commit';
    $this->get($url.'&resume=1')->assertSee('+a')->assertSee('Mark as viewed');
    $this->get($url.'&file='.hash('sha256', 'b'), $headers)->assertSee('File 2 of 3')->assertSee('+b')->assertSee('aria-pressed="true"', false);
    $this->get($overview)->assertSee('1 of 3 files viewed');
    $account = 'another-reviewer';
    $this->get($overview)->assertDontSee('Continue review')->assertDontSee('files viewed');
});

it('posts an explicit PR comment once and retains the draft on an unconfirmed response', function (): void {
    $posts = [];
    $fail = false;
    Process::fake(function (PendingProcess $process) use (&$posts, &$fail) {
        if ($process->command[1] === 'config') {
            return Process::result('reviewer');
        }
        expect($process->command)->toContain('POST', '--input', '-', 'repos/example/demo/issues/4/comments');
        $posts[] = json_decode($process->input, true, flags: JSON_THROW_ON_ERROR);

        return Process::result(exitCode: $fail ? 1 : 0);
    });
    $scope = hash('sha256', (new GitHubClient)->reviewKey('example/demo', 4));
    $url = '/cloud/pulls/4/comments?repository=example/demo';
    $body = ['scope' => $scope, 'submission' => (string) Str::uuid(), 'comment' => "Reviewed the change.\n\nLooks good."];
    $this->post($url, ['scope' => str_repeat('c', 64)] + $body)->assertConflict();
    $this->post($url, $body)->assertSessionHas('posted', $scope);
    $this->post($url, $body)->assertSessionHas('notice', 'This submission was already received. Check the PR conversation.');
    expect($posts)->toBe([['body' => $body['comment']]]);
    $fail = true;
    $this->post($url, ['submission' => (string) Str::uuid()] + $body)->assertSessionHasInput('comment', $body['comment']);
});

it('confirms the reviewed revision before publishing inline feedback or marking a draft ready', function (): void {
    $writes = [];
    Process::fake(function (PendingProcess $process) use (&$writes) {
        if ($process->command[1] === 'config') {
            return Process::result('reviewer');
        }
        if (in_array('GET', $process->command, true)) {
            return Process::result(json_encode([['node_id' => 'PR_demo', 'head_sha' => 'tip', 'base_sha' => 'base', 'state' => 'open', 'draft' => true, 'changed_files' => 3]]));
        }
        $payload = json_decode($process->input, true, flags: JSON_THROW_ON_ERROR);
        $writes[] = $payload;

        return Process::result(json_encode(['data' => ['markPullRequestReadyForReview' => ['pullRequest' => ['isDraft' => false]]]]));
    });
    $scope = hash('sha256', (new GitHubClient)->reviewKey('example/demo', 4));
    $revision = ['scope' => $scope, 'head' => 'tip', 'base' => 'base'];
    $ready = '/cloud/pulls/4/ready?repository=example/demo';
    $line = '/cloud/pulls/4/lines?repository=example/demo';
    $draft = ['submission' => (string) Str::uuid(), 'body' => 'Check the deleted line', 'path' => 'source.md', 'line' => 2, 'side' => 'LEFT'];
    $this->post($ready, ['scope' => 'other'] + $revision)->assertSessionHas('notice', 'The GitHub account changed. Review the pull request again.');
    $this->post($line, ['head' => 'previous'] + $revision + $draft)->assertSessionHasInput('body', $draft['body']);
    expect($writes)->toBeEmpty();
    $this->post($line, $revision + $draft)->assertSessionHas('notice', 'Line comment posted to GitHub.');
    $this->post($line, $revision + $draft)->assertRedirect();
    $this->post($ready, $revision)->assertSessionHas('notice', 'Pull request is ready for review.');
    expect($writes)->toHaveCount(2)
        ->and($writes[0])->toBe(['commit_id' => 'tip', 'path' => 'source.md', 'line' => 2, 'side' => 'LEFT', 'body' => 'Check the deleted line'])
        ->and($writes[1]['variables'])->toBe(['id' => 'PR_demo']);
});

it('reports failed checks, requested changes and line replies only against the displayed diff', function (): void {
    $comments = [['id' => 7, 'line' => 2, 'side' => 'RIGHT', 'body' => 'Question'], ['id' => 8, 'parent' => 7, 'body' => 'Reply']];
    Process::fake(function (PendingProcess $process) use ($comments) {
        $endpoint = end($process->command);
        if ($endpoint === 'graphql') {
            return Process::result(json_encode(['data' => ['repository' => ['pullRequest' => [
                'headRefOid' => 'tip', 'reviewDecision' => 'CHANGES_REQUESTED',
                'latestReviews' => ['nodes' => [['author' => ['login' => 'reviewer'], 'state' => 'CHANGES_REQUESTED']]],
                'commits' => ['nodes' => [['commit' => ['statusCheckRollup' => ['state' => 'FAILURE', 'contexts' => [
                    'totalCount' => 2, 'nodes' => [['name' => 'Unit tests', 'conclusion' => 'FAILURE'], ['context' => 'Build', 'state' => 'PENDING']],
                ]]]]]],
            ]]]]));
        }

        return Process::result(json_encode(str_contains($endpoint, '/comments?') ? $comments : [['head_sha' => 'tip', 'base_sha' => 'base']]));
    });
    $url = '/cloud/pulls/4/summary?repository=example/demo';
    $this->get($url.'&head=tip')->assertOk()->assertSee('Checks failed')->assertSee('Changes requested')->assertSee('Unit tests')->assertSee('reviewer')->assertSee('Pending');
    $this->get($url.'&head=previous')->assertStatus(503)->assertSee('New commits');
    $lines = '/cloud/pulls/4/lines?repository=example/demo&path=notes.txt&base=base';
    $this->getJson($lines.'&head=tip')->assertExactJson($comments);
    $this->getJson($lines.'&head=previous')->assertConflict();
});

it('checks thread ownership and permission before resolving or replying to a review', function (): void {
    $node = 'PR_other';
    $canReply = false;
    $writes = [];
    Process::fake(function (PendingProcess $process) use (&$node, &$canReply, &$writes) {
        if ($process->command[1] === 'config') {
            return Process::result('reviewer');
        }
        if (in_array('GET', $process->command, true)) {
            return Process::result(json_encode([['node_id' => 'PR_test', 'state' => 'open', 'head_sha' => 'tip', 'base_sha' => 'base']]));
        }
        $payload = json_decode($process->input, true);
        if (str_starts_with($payload['query'], 'query')) {
            return Process::result(json_encode(['data' => ['node' => ['path' => 'a.txt', 'isResolved' => false, 'viewerCanResolve' => true, 'viewerCanReply' => $canReply,
                'pullRequest' => ['id' => $node, 'headRefOid' => 'tip', 'baseRefOid' => 'base']]]]));
        }
        $writes[] = $payload['variables'];

        return Process::result(json_encode(['data' => ['addPullRequestReviewThreadReply' => ['comment' => ['id' => 'C']], 'resolveReviewThread' => ['thread' => ['isResolved' => true]]]]));
    });
    $scope = hash('sha256', (new GitHubClient)->reviewKey('example/demo', 4));
    $url = '/cloud/pulls/4/threads?repository=example/demo';
    $reply = ['scope' => $scope, 'head' => 'tip', 'base' => 'base', 'thread' => 'T', 'operation' => 'reply', 'body' => 'Reply'];
    $this->post($url, ['submission' => (string) Str::uuid()] + $reply)->assertSessionHasInput('body', 'Reply');
    $node = 'PR_test';
    $this->post($url, ['submission' => (string) Str::uuid()] + $reply)->assertSessionHas('notice', 'This GitHub account cannot update this conversation.');
    expect($writes)->toBeEmpty();
    $canReply = true;
    $payload = ['submission' => (string) Str::uuid()] + $reply;
    $this->post($url, $payload)->assertSessionHas('notice', 'Reply posted to GitHub.');
    $this->post($url, $payload)->assertRedirect();
    $this->post($url, ['operation' => 'resolve', 'submission' => (string) Str::uuid()] + $reply)->assertSessionHas('notice', 'Conversation resolved.');
    expect($writes)->toBe([['id' => 'T', 'body' => 'Reply'], ['id' => 'T']]);
});

it('indexes unresolved conversations across pages and files for the displayed revision', function (): void {
    Process::fake(function (PendingProcess $process) {
        if (! in_array('graphql', $process->command, true)) {
            return Process::result('[]');
        }
        $variables = json_decode($process->input, true)['variables'];
        $first = $variables['after'] === null;
        $threads = ['nodes' => [['id' => $first ? 'A' : 'B', 'path' => $first ? 'a.txt' : 'b.txt', 'isResolved' => ! $first]],
            'pageInfo' => ['hasNextPage' => $first, 'endCursor' => $first ? 'next' : null]];

        return Process::result(json_encode(['data' => ['repository' => ['pullRequest' => ['headRefOid' => 'tip', 'baseRefOid' => 'base', 'reviewThreads' => $threads]]]]));
    });
    $url = '/cloud/pulls/4/threads?repository=example/demo&path=a.txt&base=base';
    $this->getJson($url.'&head=tip')->assertOk()->assertJsonCount(2, 'threads')
        ->assertJsonPath('threads.1.url', route('cloud.pull', ['number' => 4, 'repository' => 'example/demo', 'locate' => 1, 'file' => hash('sha256', 'b.txt'), 'thread' => 'B']));
    $this->getJson($url.'&head=older')->assertConflict();
});
