<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    $this->repo = sys_get_temp_dir().'/gitobob-test-'.Str::uuid().'/demo';
    File::ensureDirectoryExists($this->repo);
    $this->git = fn (array $args): string => Process::path($this->repo)->run(['git', ...$args])->throw()->output();
    ($this->git)(['init', '-b', 'dev']);
    ($this->git)(['config', 'user.name', 'Test']);
    ($this->git)(['config', 'user.email', 'test@example.org']);
    Storage::disk('local')->put('workspace.path', dirname($this->repo));
});

afterEach(function (): void {
    File::deleteDirectory(dirname($this->repo));
});

it('stages exactly the displayed file and unstages without changing its working copy', function (bool $initial): void {
    $name = '-notes [one].txt';
    $path = $this->repo.'/'.$name;
    if ($initial) {
        file_put_contents($path, "base\n");
        ($this->git)(['--literal-pathspecs', 'add', '--', $name]);
        ($this->git)(['commit', '-m', 'Baseline']);
    }
    file_put_contents($path, "index\n");
    ($this->git)(['--literal-pathspecs', 'add', '--', $name]);
    file_put_contents($path, "working copy\n");
    file_put_contents($this->repo.'/other.txt', "untouched\n");
    $id = hash('sha256', $name);
    $url = '/repository?repo=demo&file='.$id;
    $version = $this->get($url)->assertSee('Unstage file')->assertSee('>Stage file<', false)->viewData('version');
    $payload = ['file' => $id, 'section' => 'Unstaged', 'version' => $version];
    file_put_contents($path, "edited later\n");
    $this->post('/repository/index?repo=demo', $payload)->assertSessionHas('notice', 'The file changed. Review the current diff and try again.');
    expect(($this->git)(['show', ':'.$name]))->toBe("index\n");
    $payload['version'] = $this->get($url)->viewData('version');
    $this->post('/repository/index?repo=demo', $payload)->assertSessionHas('notice', 'File staged.');
    expect(($this->git)(['show', ':'.$name]))->toBe("edited later\n");
    $payload['version'] = $this->get($url)->viewData('version');
    $payload['section'] = 'Staged';
    $this->post('/repository/index?repo=demo', $payload)->assertSessionHas('notice', 'File unstaged.');
    expect(($this->git)(['diff', '--cached', '--name-only']))->toBe('')
        ->and(file_get_contents($path))->toBe("edited later\n");
})->with([true, false]);

it('moves only the chosen hunk in both directions from the workspace review', function (): void {
    $name = 'guide.txt';
    $path = $this->repo.'/'.$name;
    $base = implode("\n", range(1, 40))."\n";
    file_put_contents($path, $base);
    ($this->git)(['add', $name]);
    ($this->git)(['commit', '-m', 'Base']);
    $edit = str_replace(["\n2\n", "\n30\n"], ["\nfirst\n", "\nsecond\n"], $base);
    file_put_contents($path, $edit);
    $id = hash('sha256', $name);
    $url = '/changes?repo=demo&file='.$id;
    $submit = function (string $section, int $hunk) use ($url, $id) {
        $page = $this->get($url)->assertOk();

        return $this->post('/repository/index?repo=demo&all=1', [
            'section' => $section, 'hunk' => $hunk, 'file' => $id, 'version' => $page->viewData('version'),
            'patch_version' => hash('sha256', $page->viewData('previews')[$section]),
        ]);
    };
    $submit('Unstaged', 1)->assertSessionHas('notice', 'Change staged.');
    expect(($this->git)(['show', ':guide.txt']))->toBe(str_replace("\n30\n", "\nsecond\n", $base));
    $submit('Staged', 0)->assertSessionHas('notice', 'Change unstaged.');
    expect(($this->git)(['show', ':guide.txt']))->toBe($base)->and(file_get_contents($path))->toBe($edit);
});

it('reviews and commits only the approved staged snapshot in its repository', function (): void {
    $file = $this->repo.'/guide.md';
    file_put_contents($file, "first\n");
    ($this->git)(['add', '.']);
    $url = '/repository/commit?repo=demo';
    $version = $this->get($url)->assertSee('+first')->assertSee('dev')->viewData('version');
    file_put_contents($file, "staged\n");
    ($this->git)(['add', '.']);
    $this->post($url, ['message' => 'Sample commit', 'version' => $version])->assertSessionHas('notice', 'The branch or staged changes changed. Review the updated preview before committing.');
    file_put_contents($file, "only on disk\n");
    $version = $this->get($url)->assertSee('+staged')->assertDontSee('only on disk')->viewData('version');
    $payload = ['message' => 'Sample commit', 'version' => $version];
    $this->post($url, $payload)->assertSessionHas('notice', 'Local commit created.');
    $this->post($url, $payload)->assertSessionHas('notice', 'The branch or staged changes changed. Review the updated preview before committing.');
    expect(($this->git)(['show', 'HEAD:guide.md']))->toBe("staged\n")
        ->and(($this->git)(['log', '-1', '--format=%s']))->toBe("Sample commit\n")
        ->and(file_get_contents($file))->toBe("only on disk\n");
    $this->post('/repository/index?repo=..', [])->assertNotFound();
});

it('unstages both sides of a rename while keeping the new path on disk', function (): void {
    file_put_contents($this->repo.'/before.txt', "text\n");
    ($this->git)(['add', '.']);
    ($this->git)(['commit', '-m', 'Baseline']);
    ($this->git)(['mv', 'before.txt', 'after.txt']);
    $id = hash('sha256', 'after.txt');
    $page = $this->get('/repository?repo=demo&file='.$id)->assertOk();
    $this->post('/repository/index?repo=demo', ['version' => $page->viewData('version'), 'file' => $id, 'section' => 'Staged'])
        ->assertSessionHas('notice', 'File unstaged.');
    expect(($this->git)(['diff', '--cached', '--name-only']))->toBe('')
        ->and(file_get_contents($this->repo.'/after.txt'))->toBe("text\n");
});

it('stages and unstages selected rows inside one hunk without touching the working file', function (): void {
    $name = 'two edits.txt';
    $file = $this->repo.'/'.$name;
    file_put_contents($file, "start\nold A\nold B\nend");
    ($this->git)(['add', '.']);
    ($this->git)(['commit', '-m', 'Base']);
    $working = "start\nnew A\nnew B\nend";
    file_put_contents($file, $working);
    $id = hash('sha256', $name);
    $payload = function (string $section, array $rows) use ($id): array {
        $page = $this->get('/repository?repo=demo&file='.$id)->assertOk();
        $patch = $page->viewData('previews')[$section];
        $body = explode("\n", substr($patch, strpos($patch, "\n@@ ") + 1));
        $indices = array_keys(array_intersect($body, $rows));
        expect($indices)->toHaveCount(count($rows));

        return ['file' => $id, 'section' => $section, 'version' => $page->viewData('version'), 'patch_version' => hash('sha256', $patch),
            'intent' => 'lines', 'lines' => implode(',', $indices)];
    };
    $url = '/repository/index?repo=demo';
    $stage = $payload('Unstaged', ['-old A', '+new A']);
    $this->post($url, ['lines' => '0'] + $stage)->assertSessionHas('notice', 'The selected lines changed. Review the current diff.');
    $this->post($url, $stage)->assertSessionHas('notice', 'Selected lines staged.');
    $this->post($url, $stage)->assertSessionHas('notice', 'The file changed. Review the current diff and try again.');
    expect(($this->git)(['show', ':'.$name]))->toBe("start\nnew A\nold B\nend")->and(file_get_contents($file))->toBe($working);
    $this->post($url, $payload('Staged', ['-old A', '+new A']))->assertSessionHas('notice', 'Selected lines unstaged.');
    expect(($this->git)(['diff', '--cached']))->toBe('')->and(file_get_contents($file))->toBe($working);
});

it('stages just one line of a new file and preserves its mode and final newline', function (): void {
    $name = 'script with spaces';
    file_put_contents($this->repo.'/'.$name, "first\nsecond");
    chmod($this->repo.'/'.$name, 0755);
    $id = hash('sha256', $name);
    $page = $this->get('/repository?repo=demo&file='.$id)->assertOk();
    $patch = $page->viewData('previews')['Untracked'];
    $this->post('/repository/index?repo=demo', ['file' => $id, 'section' => 'Untracked', 'version' => $page->viewData('version'),
        'intent' => 'lines', 'lines' => '2', 'patch_version' => hash('sha256', $patch)])->assertSessionHas('notice', 'Selected lines staged.');
    expect(($this->git)(['show', ':'.$name]))->toBe('second')->and(($this->git)(['ls-files', '-s']))->toStartWith('100755');
});

it('keeps the original order and positions when staging rows from separate hunks', function (): void {
    $file = $this->repo.'/list';
    $base = implode("\n", range(1, 40))."\n";
    file_put_contents($file, $base);
    ($this->git)(['add', '.']);
    ($this->git)(['commit', '-m', 'Base']);
    $working = str_replace(["\n2\n", "\n30\n"], ["\n2\nfirst\nextra\n", "\n30\nsecond\n"], $base);
    file_put_contents($file, $working);
    $id = hash('sha256', 'list');
    $page = $this->get('/repository?repo=demo&file='.$id);
    $patch = $page->viewData('previews')['Unstaged'];
    $body = explode("\n", substr($patch, strpos($patch, "\n@@ ") + 1));
    $rows = array_keys(array_intersect($body, ['+first', '+second']));
    $this->post('/repository/index?repo=demo', ['file' => $id, 'section' => 'Unstaged', 'version' => $page->viewData('version'),
        'intent' => 'lines', 'lines' => implode(',', $rows), 'patch_version' => hash('sha256', $patch)])->assertSessionHas('notice', 'Selected lines staged.');
    expect(($this->git)(['show', ':list']))->toBe(str_replace("extra\n", '', $working))->and(file_get_contents($file))->toBe($working);
});
