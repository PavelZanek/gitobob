<?php

namespace App\Http\Controllers;

use App\Git\RepositoryScanner;
use App\Preferences;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Native\Desktop\Facades\Shell;

final class SettingsController
{
    public function checkForUpdates(Request $request): RedirectResponse
    {
        $this->requireLocalRequest($request);
        $current = config()->string('nativephp.version');
        $redirect = to_route('settings')->withFragment('updates');
        try {
            $response = Http::acceptJson()->withUserAgent('GitoBob/'.$current)->timeout(6)
                ->get('https://api.github.com/repos/PavelZanek/gitobob/releases', ['per_page' => 100]);
        } catch (ConnectionException) {
            return $redirect->with('updateStatus', 'Could not check GitHub. Try again later.');
        }

        $releases = $response->json();
        if (! $response->successful() || ! is_array($releases) || ! array_is_list($releases)) {
            return $redirect->with('updateStatus', 'Could not check GitHub. Try again later.');
        }

        $latest = $current;
        $releaseTag = null;
        foreach ($releases as $release) {
            $tag = $release['tag_name'] ?? null;
            if (($release['draft'] ?? true) || ! is_string($tag) || ! preg_match('/^v?(\d+\.\d+\.\d+(?:-(?:alpha|beta|rc)\.\d+)?)$/D', $tag, $match)) {
                continue;
            }
            if (! str_contains($current, '-') && (($release['prerelease'] ?? false) || str_contains($tag, '-'))) {
                continue;
            }
            if (version_compare($match[1], $latest, '>')) {
                $latest = $match[1];
                $releaseTag = $tag;
            }
        }

        if ($releaseTag !== null) {
            return $redirect->with('releaseTag', $releaseTag);
        }

        return $redirect->with('updateStatus', $releases === [] ? 'No releases have been published yet.' : 'No newer release is available.');
    }

    public function openRelease(Request $request): RedirectResponse
    {
        $this->requireLocalRequest($request);
        $tag = $request->validate(['tag' => ['required', 'string', 'regex:/^v?\d+\.\d+\.\d+(?:-(?:alpha|beta|rc)\.\d+)?$/D']])['tag'];
        Shell::openExternal('https://github.com/PavelZanek/gitobob/releases/tag/'.$tag);

        return to_route('settings')->withFragment('updates')->with('releaseTag', $tag);
    }

    private function requireLocalRequest(Request $request): void
    {
        abort_unless(in_array($request->getHost(), ['localhost', '127.0.0.1', '[::1]'], true)
            && in_array($request->ip(), ['127.0.0.1', '::1'], true), 403);
    }

    public function update(Request $request, RepositoryScanner $scanner): RedirectResponse
    {
        $this->requireLocalRequest($request);
        $values = $request->validate(['language' => ['sometimes', 'required', 'in:en,cs'], 'theme' => ['required', 'in:system,light,dark'], 'depth' => ['present', 'nullable', 'integer', 'min:0']]);
        $previous = Preferences::read();
        $settings = new Preferences($values['theme'], $values['depth'] === null ? null : (int) $values['depth'], $values['language'] ?? $previous->language);
        $changed = $settings->depth !== $previous->depth;
        $settings->save();
        if ($changed) {
            $scanner->forgetWorkspace();
        }

        return to_route('settings')->with('notice', 'Settings saved.');
    }
}
