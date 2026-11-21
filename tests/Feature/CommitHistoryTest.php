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

it('pages a pinned branch history and opens the first changed file without a remote', function (): void {
    $url = '/repository/history?repo=demo';
    $this->get($url)->assertOk()->assertSee('No commits on this page.');
    foreach (['a.txt', 'z.txt'] as $name) {
        file_put_contents($this->repo.'/'.$name, $name."\n");
    }
    ($this->git)(['add', '.']);
    ($this->git)(['commit', '-m', 'Initial pair']);
    $root = trim(($this->git)(['rev-parse', 'HEAD']));
    for ($i = 1; $i <= 30; $i++) {
        ($this->git)(['commit', '--allow-empty', '-m', 'Revision '.$i]);
    }
    $tip = $this->get($url)->assertSee('Load older commits')->assertDontSee('Initial pair')->viewData('tip');
    ($this->git)(['commit', '--allow-empty', '-m', 'Later change']);
    $this->get($url.'&tip='.$tip.'&page=2')->assertSee('Initial pair')->assertDontSee('Revision 1')->assertSee('Newer commits');
    $this->get($url.'&commit='.$root)->assertSee('+a.txt')->assertDontSee('+z.txt');
    $this->get($url.'&commit='.$root.'&file='.hash('sha256', 'z.txt'))->assertSee('+z.txt')->assertDontSee('+a.txt');
    $this->get($url.'&commit='.$tip)->assertSee('No files changed');
    $this->get($url.'&commit='.str_repeat('f', 40))->assertNotFound();
    $this->get($url.'&path=../outside')->assertNotFound();
});

it('follows the actual historical pathname across a rename and a page boundary', function (): void {
    $old = 'z [draft].md';
    $new = '-released [1].md';
    file_put_contents($this->repo.'/'.$old, "Original draft\n");
    file_put_contents($this->repo.'/a.txt', "Other file\n");
    ($this->git)(['add', '.']);
    ($this->git)(['commit', '-m', 'Original pair']);
    ($this->git)(['mv', '--', $old, $new]);
    ($this->git)(['commit', '-m', 'Rename document']);
    for ($i = 1; $i <= 29; $i++) {
        file_put_contents($this->repo.'/'.$new, 'Edit '.$i."\n", FILE_APPEND);
        ($this->git)(['commit', '-am', 'Update '.$i]);
    }
    $url = '/repository/history?repo=demo&path='.rawurlencode($new);
    $this->get($url)->assertSee('Rename document')->assertSee('Load older commits')->assertDontSee('Original pair');
    $page = $this->get($url.'&page=2')->assertSee('Original pair');
    $entry = $page->viewData('commits')[0];
    expect($entry['path'])->toBe($old);
    $file = hash('sha256', $old);
    $page->assertSee('file='.$file);
    $this->get($url.'&commit='.$entry['oid'].'&file='.$file)->assertSee('+Original draft')->assertDontSee('+Other file');
});

it('links directly to the partial commit after HEAD moves', function (): void {
    file_put_contents($this->repo.'/notes', "Approved\n");
    ($this->git)(['add', '.']);
    file_put_contents($this->repo.'/notes', "Approved\nUnpublished\n");
    $review = '/repository/commit?repo=demo';
    $version = $this->get($review)->viewData('version');
    $this->post($review, ['version' => $version, 'message' => 'Approve review'])->assertSessionHas('notice', 'Local commit created.');
    $created = trim(($this->git)(['rev-parse', 'HEAD']));
    ($this->git)(['commit', '--allow-empty', '-m', 'Another commit']);
    $this->get('/repository?repo=demo')->assertSee('View commit')->assertSee('commit='.$created)->assertSee('+Unpublished');
    $this->get('/repository/history?repo=demo&commit='.$created)->assertSee('Approve review')->assertSee('+Approved')->assertDontSee('Unpublished');
});
