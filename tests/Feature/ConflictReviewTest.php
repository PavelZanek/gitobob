<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    $this->root = sys_get_temp_dir().'/gitobob-conflicts-'.Str::uuid();
    $this->repo = $this->root.'/demo';
    File::ensureDirectoryExists($this->repo);
    $this->git = fn (array $args): string => trim(Process::path($this->repo)->run(['git', ...$args])->throw()->output());
    ($this->git)(['init', '-b', 'dev']);
    ($this->git)(['config', 'user.name', 'Test']);
    ($this->git)(['config', 'user.email', 'test@example.org']);
    foreach (['guide.md', 'notes.md'] as $name) {
        file_put_contents($this->repo.'/'.$name, "Original\n");
    }
    ($this->git)(['add', '.']);
    ($this->git)(['commit', '-m', 'Original']);
    file_put_contents($this->repo.'/guide.md', "Stashed title\n");
    file_put_contents($this->repo.'/notes.md', "Saved notes\n");
    ($this->git)(['stash', 'push', '-m', 'Review']);
    ($this->git)(['switch', '-c', 'incoming']);
    file_put_contents($this->repo.'/guide.md', "Current title\n");
    ($this->git)(['rm', 'notes.md']);
    ($this->git)(['commit', '-am', 'Incoming changes']);
    file_put_contents($this->repo.'/aaa.md', "Unrelated\n");
    Storage::disk('local')->put('workspace.path', $this->root);
    $this->review = '/repository/conflicts?repo=demo';
    $this->resolve = '/repository/conflicts/resolve?repo=demo';
    $stash = $this->get('/repository/stashes?repo=demo');
    $this->applied = $this->followingRedirects()->post('/repository/stashes/apply?repo=demo', ['stash' => $stash->viewData('oid'), 'version' => $stash->viewData('version')]);
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

it('opens the first conflict, refreshes edited text, advances and shows the reviewed staged result', function (): void {
    $this->applied->assertOk()->assertSee('2 conflicted files')->assertSee('Current title')->assertSee('Stashed changes')->assertSee('Stashed title');
    $id = $this->applied->viewData('id');
    expect($id)->toBe(hash('sha256', 'guide.md'));
    $page = $this->get($this->review.'&file='.hash('sha256', 'notes.md'))->assertSee('Deleted on this side.')->assertSee('Saved notes');
    $this->post($this->resolve, ['file' => $id, 'version' => $this->applied->viewData('working')['version']])->assertSessionHas('notice', 'Review the current working file before marking it as resolved.');
    $this->get($this->review, ['X-GitoBob-Revision' => $this->applied->viewData('revision')])->assertNoContent();
    file_put_contents($this->repo.'/guide.md', "Reviewed title\n");
    $updated = $this->get($this->review, ['X-GitoBob-Revision' => $this->applied->viewData('revision')])->assertOk()->assertSee('Reviewed title');
    file_put_contents($this->repo.'/guide.md', "Final title\n");
    $this->post($this->resolve, ['file' => $id, 'version' => $updated->viewData('working')['version']])->assertSessionHas('notice', 'Review the current working file before marking it as resolved.');
    $updated = $this->get($this->review);
    $next = $this->followingRedirects()->post($this->resolve, ['file' => $id, 'version' => $updated->viewData('working')['version']])
        ->assertSee('1 conflicted file')->assertSee('notes.md')->assertSee('Stashed changes');
    $this->followingRedirects()->post($this->resolve, ['file' => $page->viewData('id'), 'version' => $next->viewData('working')['version']])
        ->assertSee('All conflicts resolved')->assertSee('Staged diff')->assertSee('+Final title')->assertSee('+Saved notes');
    expect(($this->git)(['ls-files', '-u']))->toBe('')->and(($this->git)(['stash', 'list']))->toContain('Review')
        ->and(($this->git)(['status', '--porcelain', '--', 'aaa.md']))->toBe('?? aaa.md');
});

it('stages a reviewed deletion and refuses to open a symlink or unrelated file in the editor', function (): void {
    $id = hash('sha256', 'notes.md');
    unlink($this->repo.'/notes.md');
    symlink($this->root, $this->repo.'/notes.md');
    $this->post('/repository/conflicts/editor?repo=demo', ['file' => $id])->assertNotFound();
    $this->post('/repository/conflicts/editor?repo=demo', ['file' => hash('sha256', 'aaa.md')])->assertNotFound();
    unlink($this->repo.'/notes.md');
    $file = $this->get($this->review.'&file='.$id)->assertSee('stage its deletion');
    $this->post($this->resolve, ['file' => $id, 'version' => $file->viewData('working')['version']])->assertRedirect();
    expect(($this->git)(['ls-files', '--', 'notes.md']))->toBe('')->and(($this->git)(['diff', '--name-only', '--diff-filter=U']))->toBe('guide.md');
});
