<?php

namespace App\Git;

use Illuminate\Support\Facades\Process;

final class CommitHistory
{
    private const int PAGE_SIZE = 30;

    private const string FORMAT = '--format=%H%x00%h%x00%an%x00%ai%x00%s';

    public function revision(string $directory, string $ref): ?string
    {
        $result = Process::path($directory)->run(['git', 'rev-parse', '--verify', '--quiet', $ref.'^{commit}']);

        return $result->successful() ? trim($result->output()) : null;
    }

    /** @return array{short:string,author:string,date:string,subject:string} */
    public function summary(string $directory, string $commit): array
    {
        $output = Process::path($directory)->run(['git', 'show', '--no-patch', '--no-notes', '--no-show-signature',
            '--format=%h%x00%an%x00%ai%x00%s', $commit, '--'])->throw()->output();
        [$short, $author, $date, $subject] = explode("\0", rtrim($output, "\n"));

        return ['short' => $short, 'author' => $author, 'date' => $date, 'subject' => $subject];
    }

    /** @return array{commits:list<array{oid:string,short:string,author:string,date:string,subject:string,path:?string}>, older:bool} */
    public function listing(string $directory, string $tip, string $path, int $page): array
    {
        $skip = ($page - 1) * self::PAGE_SIZE;
        $args = ['git', '--literal-pathspecs', 'log', '--no-color', '--no-decorate', '--no-notes', '--no-show-signature', '-z', self::FORMAT];
        if ($path === '') {
            array_push($args, '--skip='.$skip, '-n', (string) (self::PAGE_SIZE + 1), $tip, '--');
        } else {
            // Git must walk the newer renames to discover the name on an older page.
            array_push($args, '--follow', '--find-renames', '--name-status', '-n', (string) ($skip + self::PAGE_SIZE + 1), $tip, '--', $path);
        }
        $fields = explode("\0", Process::path($directory)->run($args)->throw()->output());
        $commits = [];
        for ($i = 0; $fields[$i] !== '';) {
            [$oid, $short, $author, $date, $subject] = array_slice($fields, $i, 5);
            $i += 5;
            $historicalPath = null;
            while (preg_match('/^\s*([ACDMRTUXB])[0-9]*$/D', $fields[$i], $status)) {
                $i++;
                $historicalPath = $fields[$i++];
                if (in_array($status[1], ['R', 'C'], true)) {
                    $historicalPath = $fields[$i++];
                }
            }
            $commits[] = ['oid' => $oid, 'short' => $short, 'author' => $author, 'date' => $date,
                'subject' => $subject, 'path' => $historicalPath];
        }
        $offset = $path === '' ? 0 : $skip;

        return ['commits' => array_slice($commits, $offset, self::PAGE_SIZE), 'older' => count($commits) > $offset + self::PAGE_SIZE];
    }
}
