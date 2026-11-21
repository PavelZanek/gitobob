<?php

use Illuminate\Support\Facades\Storage;

it('offers the folder picker on first launch', function (): void {
    Storage::fake('local');
    $this->get('/')->assertOk()->assertSee('Choose folder')->assertSee('Choose a workspace folder');
});
