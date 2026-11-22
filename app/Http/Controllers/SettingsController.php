<?php

namespace App\Http\Controllers;

use App\Git\RepositoryScanner;
use App\Preferences;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SettingsController
{
    public function update(Request $request, RepositoryScanner $scanner): RedirectResponse
    {
        abort_unless(in_array($request->getHost(), ['localhost', '127.0.0.1', '[::1]'], true)
            && in_array($request->ip(), ['127.0.0.1', '::1'], true), 403);
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
