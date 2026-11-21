<?php

namespace App\Git;

final readonly class RepositoryFolder
{
    /** @param list<self> $children */
    public function __construct(
        public string $name,
        public bool $repository,
        public array $children,
    ) {}
}
