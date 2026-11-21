<?php

namespace App\Git;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;

final class GitHubClient
{
    public const string REPOSITORY = '[A-Za-z0-9-]+/(?!\.{1,2}$)[A-Za-z0-9_.-]+';

    public const int FILES_PER_PAGE = 30;

    public const int MAX_PR_FILES = 3000;

    public function remote(string $directory): ?string
    {
        $result = Process::path($directory)->run(['git', 'config', '--null', '--get-regexp', '^remote\..*\.url$']);
        $remotes = [];
        foreach (explode("\0", $result->output()) as $entry) {
            if (! preg_match('/^remote\.(.+)\.url\n(.+)$/D', $entry, $parts)) {
                continue;
            }
            $slug = self::slug($parts[2]);
            if ($slug !== null) {
                $remotes[$parts[1]] = $slug;
            }
        }

        return $remotes['github'] ?? $remotes['origin'] ?? (array_values($remotes)[0] ?? null);
    }

    public static function slug(string $url): ?string
    {
        if (preg_match('~^(?:git@)?github\.com:(.+)$~iD', $url, $scp)) {
            $path = $scp[1];
        } elseif (strtolower((string) parse_url($url, PHP_URL_HOST)) === 'github.com'
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['https', 'ssh', 'git', 'http'], true)) {
            $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        } else {
            return null;
        }
        $slug = preg_replace('~\.git$~i', '', rtrim($path, '/'));

        return is_string($slug) && preg_match('~^'.self::REPOSITORY.'$~D', $slug) ? $slug : null;
    }

    public function matchingPull(string $repository, string $base, string $source, string $head): ?int
    {
        $query = http_build_query(['state' => 'open', 'base' => $base, 'head' => explode('/', $source)[0].':'.$head, 'per_page' => 100]);
        $rows = $this->read('repos/'.$repository.'/pulls?'.$query, 'map({number, source:.head.repo.full_name})');
        foreach ($rows as $row) {
            if (strcasecmp($row['source'] ?? '', $source) === 0) {
                return (int) $row['number'];
            }
        }

        return null;
    }

    public function branchTip(string $repository, string $branch): ?string
    {
        return $this->read('repos/'.$repository.'/branches/'.rawurlencode($branch), '[.commit.sha]', false, true)[0] ?? null;
    }

    public function configuredAccount(): ?string
    {
        // Environment tokens can override the account selected in gh's local config.
        if (getenv('GH_TOKEN') || getenv('GITHUB_TOKEN')) {
            return null;
        }
        $account = Process::run([$this->binary(), 'config', 'get', 'user', '--host', 'github.com']);
        if (! $account->successful() || trim($account->output()) === '') {
            return null;
        }

        return trim($account->output());
    }

    /** @throws RuntimeException */
    public function authenticatedAccount(): string
    {
        [$login] = $this->read('user', '[.login]', false);

        return $login;
    }

    public function scopeKey(string $login, ?string $repository): string
    {
        return 'github-overview:'.hash('sha256', strtolower($login.':'.$repository));
    }

    /** @throws RuntimeException */
    public function reviewKey(string $repository, int $number): string
    {
        return $this->scopeKey($this->configuredAccount() ?? $this->authenticatedAccount(), $repository.'/pull/'.$number);
    }

    /** @return array<string, mixed> */
    public function overview(?string $repository): array
    {
        if ($repository === null) {
            return ['repositories' => $this->read('user/repos?sort=pushed&per_page=100', 'map(.full_name)')];
        }

        return [
            'pulls' => $this->read('repos/'.$repository.'/pulls?state=open&sort=updated&per_page=100',
                'map({number,title,author:.user.login,head:.head.label,base:.base.ref,updated:.updated_at,draft})'),
            'branches' => $this->read('repos/'.$repository.'/branches?per_page=100', 'map(.name)'),
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RuntimeException when GitHub cannot return the pull request
     */
    public function pullMetadata(string $repository, int $number): array
    {
        $endpoint = 'repos/'.$repository.'/pulls/'.$number;
        [$pull] = $this->read($endpoint, '[{title, draft, state, node_id, head:.head.label, base:.base.ref, base_sha:.base.sha, head_sha:.head.sha, changed_files}]', false);

        return $pull;
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws RuntimeException
     */
    public function filePage(string $endpoint, int $page, int $size): array
    {
        $files = $this->read($endpoint.'/files?per_page='.$size.'&page='.$page,
            'map({filename, previous_filename, sha, status, additions, deletions, patch: (.patch // "")[:65536], limited: ((.patch // "") | length > 65536)})', false);
        foreach ($files as &$entry) {
            $entry['id'] = hash('sha256', $entry['filename']);
            $entry['version'] = hash('sha256', json_encode($entry, JSON_THROW_ON_ERROR));
        }

        return $files;
    }

    public function createPull(string $repository, string $base, string $source, string $head, string $title, string $body, bool $draft): int
    {
        $payload = compact('base', 'head', 'title', 'body', 'draft');
        if (strcasecmp($source, $repository) !== 0) {
            [$owner, $name] = explode('/', $source, 2);
            $payload['head'] = $owner.':'.$head;
            $payload['head_repo'] = $name;
        }
        try {
            $result = Process::timeout(30)->env(['GH_PROMPT_DISABLED' => '1', 'GH_NO_UPDATE_NOTIFIER' => '1'])
                ->input(json_encode($payload, JSON_THROW_ON_ERROR))
                ->run([$this->binary(), 'api', '--hostname', 'github.com', '--method', 'POST', '--input', '-', '--jq', '.number', 'repos/'.$repository.'/pulls']);
        } catch (ProcessTimedOutException) {
            throw new RuntimeException('GitHub did not confirm creation. Your text is saved. Retry to check for an existing pull request.', 503);
        }
        if (! $result->successful() && str_contains(strtolower($result->errorOutput()), 'draft pull requests are not supported')) {
            throw new RuntimeException('GitHub does not support draft pull requests for this repository. Your text is saved.', 422);
        }
        $number = filter_var(trim($result->output()), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! $result->successful() || $number === false) {
            throw new RuntimeException('GitHub could not confirm creation. Your text is saved. Check access and retry to look up the pull request.', 503);
        }

        return $number;
    }

    /** @return array<string, mixed> */
    public function reviewSummary(string $repository, int $number): array
    {
        [$owner, $name] = explode('/', $repository, 2);
        $query = <<<'GRAPHQL'
        query($owner:String!, $name:String!, $number:Int!) {
          repository(owner:$owner,name:$name) { pullRequest(number:$number) {
            headRefOid reviewDecision latestReviews(last:10) { nodes { author { login } state } }
            commits(last:1) { nodes { commit { statusCheckRollup {
              state contexts(first:100) { totalCount nodes {
                ... on CheckRun { name status conclusion }
                ... on StatusContext { context state }
              } }
            } } } }
          } }
        }
        GRAPHQL;
        $result = $this->request('graphql', ['query' => $query, 'variables' => compact('owner', 'name', 'number')]);
        $pull = $result['data']['repository']['pullRequest'] ?? null;
        if (! is_array($pull)) {
            throw new RuntimeException('GitHub could not load the review status.');
        }

        return $pull;
    }

    public function markReady(string $node): void
    {
        $response = $this->request('graphql', [
            'query' => 'mutation($id:ID!) { markPullRequestReadyForReview(input:{pullRequestId:$id}) { pullRequest { isDraft } } }',
            'variables' => ['id' => $node],
        ]);
        if (($response['data']['markPullRequestReadyForReview']['pullRequest']['isDraft'] ?? null) !== false) {
            throw new RuntimeException('GitHub did not confirm the status change. Refresh the pull request.');
        }
    }

    /** @return list<array<string, mixed>> */
    public function lineComments(string $repository, int $number, string $path): array
    {
        return $this->read('repos/'.$repository.'/pulls/'.$number.'/comments?per_page=100',
            'map(select(.path == '.json_encode($path, JSON_THROW_ON_ERROR).' and .subject_type != "file") | {id, parent:.in_reply_to_id, line, side, author:.user.login, body})');
    }

    public function postLineComment(string $repository, int $number, string $commit, string $path, int $line, string $side, string $body): void
    {
        $this->request('repos/'.$repository.'/pulls/'.$number.'/comments', ['commit_id' => $commit] + compact('path', 'line', 'side', 'body'));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function request(string $endpoint, array $payload): array
    {
        try {
            $result = Process::timeout(30)->env(['GH_PROMPT_DISABLED' => '1', 'GH_NO_UPDATE_NOTIFIER' => '1'])
                ->input(json_encode($payload, JSON_THROW_ON_ERROR))
                ->run([$this->binary(), 'api', '--hostname', 'github.com', '--method', 'POST', '--input', '-', $endpoint]);
        } catch (ProcessTimedOutException) {
            throw new RuntimeException('GitHub took too long to respond. Refresh before trying again.');
        }
        $response = json_decode($result->output(), true);
        if (! $result->successful() || ! is_array($response) || isset($response['errors'])) {
            throw new RuntimeException('GitHub could not confirm the request. Check your access and refresh before trying again.');
        }

        return $response;
    }

    public function postComment(string $repository, int $number, string $body): void
    {
        try {
            $result = Process::timeout(30)->env(['GH_PROMPT_DISABLED' => '1', 'GH_NO_UPDATE_NOTIFIER' => '1'])
                ->input(json_encode(['body' => $body], JSON_THROW_ON_ERROR))
                ->run([$this->binary(), 'api', '--hostname', 'github.com', '--method', 'POST', '--input', '-',
                    '--silent', 'repos/'.$repository.'/issues/'.$number.'/comments']);
        } catch (ProcessTimedOutException) {
            throw new RuntimeException('GitHub did not confirm the comment. Check the conversation before posting again.');
        }
        if (! $result->successful()) {
            throw new RuntimeException('GitHub did not confirm the comment. Check the conversation and your write access before posting again.');
        }
    }

    /** @return list<mixed> */
    private function read(string $endpoint, string $projection, bool $paginate = true, bool $allowMissing = false): array
    {
        try {
            $command = [$this->binary(), 'api', '--hostname', 'github.com', '--method', 'GET'];
            if ($paginate) {
                $command[] = '--paginate';
            }
            $result = Process::timeout(30)->env(['GH_PROMPT_DISABLED' => '1', 'GH_NO_UPDATE_NOTIFIER' => '1'])
                ->run([...$command, '--jq', $projection.' | @json', $endpoint]);
        } catch (ProcessTimedOutException) {
            throw new RuntimeException('GitHub took too long to respond. Try refreshing.', 503);
        }
        if (! $result->successful()) {
            $error = strtolower($result->errorOutput());
            if ($allowMissing && str_contains($error, 'http 404')) {
                return [];
            }
            [$message, $status] = match (true) {
                $result->exitCode() === 127 => ['Install GitHub CLI (gh) on your Mac, then sign in with gh auth login.', 401],
                str_contains($error, 'auth login'), str_contains($error, '401'), str_contains($error, 'bad credentials') => ['Sign in to GitHub CLI on this Mac with gh auth login, then refresh.', 401],
                str_contains($error, 'rate limit'), str_contains($error, '429') => ['GitHub’s request limit has been reached. Try again later.', 503],
                str_contains($error, '404'), str_contains($error, '403') => ['This GitHub account cannot access the requested data.', 403],
                default => ['Could not reach GitHub. Check your connection and try again.', 503],
            };
            throw new RuntimeException($message, $status);
        }

        $rows = [];
        foreach (explode("\n", trim($result->output())) as $line) {
            $page = json_decode($line, true);
            if (! is_array($page) || ! array_is_list($page)) {
                throw new RuntimeException('GitHub returned an unreadable response. Try refreshing.', 503);
            }
            array_push($rows, ...$page);
        }

        return $rows;
    }

    private function binary(): string
    {
        return (new ExecutableFinder)->find('gh', 'gh', ['/opt/homebrew/bin', '/usr/local/bin']) ?? 'gh';
    }
}
