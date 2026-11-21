<?php

namespace App\Git;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;

final class GitHubClient
{
    public const string REPOSITORY = '[A-Za-z0-9-]+/(?!\.{1,2}$)[A-Za-z0-9_.-]+';

    public function remote(string $directory): ?string
    {
        $result = Process::path($directory)->run(['git', 'config', '--null', '--get-regexp', '^remote\..*\.url$']);
        $remotes = [];
        foreach (explode("\0", $result->output()) as $entry) {
            if (! preg_match('/^remote\.(.+)\.url\n(.+)$/D', $entry, $parts)) {
                continue;
            }
            $url = $parts[2];
            if (preg_match('~^(?:git@)?github\.com:(.+)$~iD', $url, $scp)) {
                $path = $scp[1];
            } elseif (strtolower((string) parse_url($url, PHP_URL_HOST)) === 'github.com'
                && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['https', 'ssh', 'git', 'http'], true)) {
                $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
            } else {
                continue;
            }
            $slug = preg_replace('~\.git$~i', '', rtrim($path, '/'));
            if (is_string($slug) && preg_match('~^'.self::REPOSITORY.'$~D', $slug)) {
                $remotes[$parts[1]] = $slug;
            }
        }

        return $remotes['github'] ?? $remotes['origin'] ?? (array_values($remotes)[0] ?? null);
    }

    public function cacheKey(?string $repository): ?string
    {
        // Environment tokens can override the account selected in gh's local config.
        if (getenv('GH_TOKEN') || getenv('GITHUB_TOKEN')) {
            return null;
        }
        $account = Process::run([$this->binary(), 'config', 'get', 'user', '--host', 'github.com']);
        if (! $account->successful() || trim($account->output()) === '') {
            return null;
        }

        return 'github-overview:'.hash('sha256', strtolower(trim($account->output()).':'.$repository));
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

    /** @return list<mixed> */
    private function read(string $endpoint, string $projection): array
    {
        try {
            $result = Process::timeout(30)->env(['GH_PROMPT_DISABLED' => '1', 'GH_NO_UPDATE_NOTIFIER' => '1'])
                ->run([$this->binary(), 'api', '--hostname', 'github.com', '--method', 'GET', '--paginate',
                    '--jq', $projection.' | @json', $endpoint]);
        } catch (ProcessTimedOutException) {
            throw new RuntimeException('GitHub took too long to respond. Try refreshing.', 503);
        }
        if (! $result->successful()) {
            $error = strtolower($result->errorOutput());
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
