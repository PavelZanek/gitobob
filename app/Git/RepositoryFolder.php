<?php

namespace App\Git;

final readonly class RepositoryFolder
{
    /** @param list<self> $children */
    public function __construct(
        public string $name,
        public ?RepositoryStatus $status,
        public array $children,
    ) {}

    public function onlyChanged(): ?self
    {
        $children = [];
        foreach ($this->children as $child) {
            $visible = $child->onlyChanged();
            if ($visible !== null) {
                $children[] = $visible;
            }
        }

        $status = ($this->status->changedFiles ?? 0) > 0 ? $this->status : null;
        if ($status === null && $children === []) {
            return null;
        }

        return new self($this->name, $status, $children);
    }
}
