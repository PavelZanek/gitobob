<?php

namespace App\Git;

final class ChangedFile
{
    /** @var array<string, string> Git status code by section. */
    public array $sections = [];

    public string $statusRecord = '';

    public ?string $originalPath = null;

    public function __construct(public readonly string $path) {}

    public function description(string $section): string
    {
        return match ($this->sections[$section]) {
            'A' => 'Added', 'D' => 'Deleted', 'R' => 'Renamed', 'C' => 'Copied',
            'U' => 'Conflict', 'T' => 'Type changed', '?' => 'New file', default => 'Modified',
        };
    }
}
