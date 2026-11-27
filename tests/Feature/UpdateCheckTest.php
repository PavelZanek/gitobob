<?php

use Illuminate\Support\Facades\Http;
use Native\Desktop\Facades\Shell;

it('contacts GitHub only on demand and offers the highest eligible version', function (string $installed, string $expected): void {
    config(['nativephp.version' => $installed]);
    Http::fake(['api.github.com/repos/PavelZanek/gitobob/releases*' => Http::response([
        ['tag_name' => 'v0.2.0-beta.10', 'draft' => false, 'prerelease' => true],
        ['tag_name' => 'v0.1.1', 'draft' => false, 'prerelease' => false],
        ['tag_name' => 'v0.2.0-beta.2', 'draft' => false, 'prerelease' => true],
        ['tag_name' => 'v9.0.0', 'draft' => true],
        ['tag_name' => 'not-a-version', 'draft' => false],
    ])]);

    $this->get('/settings')->assertSee($installed);
    Http::assertNothingSent();
    $this->post('/settings/updates')->assertSessionHas('releaseTag', $expected);
    $this->get('/settings')->assertSee($expected)->assertSee('View release on GitHub');
    Shell::shouldReceive('openExternal')->once()->with('https://github.com/PavelZanek/gitobob/releases/tag/'.$expected);
    $this->post('/settings/updates/open', ['tag' => $expected])->assertRedirect();
})->with([['0.1.0-beta.1', 'v0.2.0-beta.10'], ['0.1.0', 'v0.1.1']]);

it('distinguishes empty releases, an up-to-date installation and GitHub errors', function (array $releases, int $status, string $message): void {
    config(['nativephp.version' => '0.1.0-beta.1']);
    Http::fake(['*' => Http::response($releases, $status)]);
    $this->post('/settings/updates')->assertSessionHas('updateStatus', $message);
})->with([
    [[], 200, 'No releases have been published yet.'],
    [[['tag_name' => 'v0.1.0-beta.1', 'draft' => false]], 200, 'No newer release is available.'],
    [[], 403, 'Could not check GitHub. Try again later.'],
]);

it('reports an offline check and rejects unrelated release destinations', function (): void {
    Http::fake(['*' => Http::failedConnection()]);
    $this->post('/settings/updates')->assertSessionHas('updateStatus', 'Could not check GitHub. Try again later.');
    $this->post('/settings/updates/open', ['tag' => 'https://elsewhere.test'])->assertSessionHasErrors('tag');
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])->post('/settings/updates')->assertForbidden();
});
