<?php

namespace App;

use Illuminate\Support\Facades\Storage;

final readonly class Preferences
{
    public const int DEFAULT_DEPTH = 5;

    public function __construct(public string $theme = 'system', public ?int $depth = self::DEFAULT_DEPTH) {}

    public static function read(): self
    {
        $values = Storage::disk('local')->json('preferences.json');
        if (! is_array($values)) {
            return new self;
        }

        return new self($values['theme'], $values['depth']);
    }

    public function save(): void
    {
        Storage::disk('local')->put('preferences.json', json_encode($this, JSON_THROW_ON_ERROR));
    }
}
