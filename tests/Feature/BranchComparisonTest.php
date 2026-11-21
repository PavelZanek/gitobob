<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Native\Desktop\Facades\Shell;

beforeEach(function (): void {
    Storage::fake('local');
    $this->repo = sys_get_temp_dir().'/gitobob-test-'.Str::uuid().'/demo';
    File::ensureDirectoryExists($this->repo);
    $this->git = fn (array $args): string => Process::path($this->repo)->run(['git', ...$args])->throw()->output();
    ($this->git)(['init', '-b', 'dev']);
    ($this->git)(['config', 'user.name', 'Test']);
    ($this->git)(['config', 'user.email', 'test@example.org']);
    ($this->git)(['remote', 'add', 'origin', 'git@github.com:example/playground.git']);
    $text = implode("\n", range(1, 20))."\n";
    file_put_contents($this->repo.'/guide.md', $text);
    ($this->git)(['add', '.']);
    ($this->git)(['commit', '-m', 'Common guide']);
    ($this->git)(['branch', 'topic/review']);
    file_put_contents($this->repo.'/guide.md', str_replace("\n18\n", "\nBase only\n", $text));
    ($this->git)(['commit', '-am', 'Change base only']);
    ($this->git)(['switch', 'topic/review']);
    file_put_contents($this->repo.'/guide.md', str_replace("\n2\n", "\nSource only\n", $text));
    file_put_contents($this->repo.'/summary.md', "Summary\n");
    ($this->git)(['add', '.']);
    ($this->git)(['commit', '-m', 'Change source only']);
    Storage::disk('local')->put('workspace.path', dirname($this->repo));
});

afterEach(function (): void {
    File::deleteDirectory(dirname($this->repo));
});

it('compares from the merge base and preserves file selection across commits even when refs move', function (): void {
    $url = '/repository/compare?repo=demo';
    $page = $this->get($url)->assertOk()->assertSee('+Source only')->assertDontSee('Base only');
    $snapshot = $page->viewData('snapshot');
    $pair = $page->viewData('pair');
    $selected = hash('sha256', 'summary.md');
    $review = $url.'&comparison='.$snapshot.'&file='.$selected;
    $this->get($review)->assertSee('+Summary');
    ($this->git)(['commit', '--allow-empty', '-m', 'Later commit']);
    $this->get($review.'&tab=commits')->assertSee('Change source only')->assertDontSee('Change base only')
        ->assertDontSee('Later commit')->assertSee('return_file='.$selected);
    $this->get('/repository/history?repo=demo&commit='.$pair['head']['oid'].'&comparison='.$snapshot.'&return_file='.$selected)
        ->assertSee('Back to comparison')->assertSee('file='.$selected);
    $this->get('/repository/compare/action?repo=demo&comparison='.$snapshot)->assertSee('The branches changed');
    $this->get($url.'&head=refs/heads/dev&base=refs/heads/topic/review')->assertSee('+Base only')->assertDontSee('Source only');
    $this->get($url.'&head=refs/heads/dev&base=refs/heads/dev')->assertSee('Choose two different branches');
    $this->get('/repository/compare?repo=..')->assertNotFound();
});

it('looks up the exact published pair and opens only the verified GitHub destination', function (string $scenario): void {
    $page = $this->get('/repository/compare?repo=demo');
    $pair = $page->viewData('pair');
    $url = '/repository/compare/action?repo=demo&comparison='.$page->viewData('snapshot');
    Process::fake(['*api*github.com*' => function (PendingProcess $process) use ($pair, $scenario) {
        $command = $process->command;
        $endpoint = end($command);
        if (str_contains($endpoint, '/pulls?')) {
            expect($endpoint)->toContain('base=dev', 'head=example%3Atopic%2Freview');
            $pulls = [['number' => 9, 'source' => 'other/playground']];
            if ($scenario === 'open') {
                $pulls[] = ['number' => 7, 'source' => 'example/playground'];
            }

            return Process::result(json_encode($pulls, JSON_THROW_ON_ERROR));
        }
        if ($endpoint === 'repos/example/playground/branches/topic%2Freview') {
            return match ($scenario) {
                'unpublished' => Process::result('', 'gh: Not Found (HTTP 404)', 1),
                'ahead' => Process::result(json_encode([$pair['ancestor']], JSON_THROW_ON_ERROR)),
                default => Process::result(json_encode([$pair['head']['oid']], JSON_THROW_ON_ERROR)),
            };
        }
        expect($endpoint)->toBe('repos/example/playground/branches/dev');

        return Process::result(json_encode([$scenario === 'stale base' ? $pair['ancestor'] : $pair['base']['oid']], JSON_THROW_ON_ERROR));
    }]);
    if (in_array($scenario, ['ahead', 'unpublished'], true)) {
        $this->get($url)->assertSee('Push…')->assertSee('repository/push')->assertDontSee('Create pull request');
    } elseif ($scenario === 'stale base') {
        $this->get($url)->assertSee('base on GitHub differs')->assertDontSee('Create pull request');
    } else {
        $destination = 'https://github.com/example/playground/'.($scenario === 'open' ? 'pull/7' : 'compare/dev...topic%2Freview?expand=1');
        $this->get($url)->assertSee($scenario === 'open' ? 'Open pull request' : 'Create pull request…')->assertSee(hash('sha256', $destination));
        Shell::shouldReceive('openExternal')->once()->with($destination);
        $this->post($url, ['destination' => 'obsolete'])->assertRedirect();
        $this->post($url, ['destination' => hash('sha256', $destination)])->assertNoContent();
    }
})->with(['open', 'create', 'unpublished', 'ahead', 'stale base']);

it('keeps local comparison available without a GitHub remote or a shared history', function (): void {
    ($this->git)(['remote', 'remove', 'origin']);
    $snapshot = $this->get('/repository/compare?repo=demo')->assertSee('+Source only')->viewData('snapshot');
    $this->get('/repository/compare/action?repo=demo&comparison='.$snapshot)->assertSee('A GitHub remote is needed');
    ($this->git)(['switch', '--orphan', 'separate']);
    ($this->git)(['commit', '--allow-empty', '-m', 'Other root']);
    $this->get('/repository/compare?repo=demo')->assertSee('no common ancestor')->assertDontSee('data-cloud-url');
});
