<?php

use Illuminate\Support\Facades\Storage;

it('offers the folder picker on first launch', function (): void {
    Storage::fake('local');
    $this->get('/')->assertOk()->assertSee('Vybrat složku')->assertSee('Vyber pracovní složku');
});
