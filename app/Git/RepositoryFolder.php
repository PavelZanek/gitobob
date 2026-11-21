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

    /** @param array<string,RepositoryStatus> $repositories */
    public static function fromRepositories(string $name, array $repositories): ?self
    {
        if ($repositories === []) {
            return null;
        }
        $status = $repositories['.'] ?? null;
        unset($repositories['.']);
        $groups = [];
        foreach ($repositories as $relative => $repository) {
            [$child, $rest] = array_pad(explode('/', (string) $relative, 2), 2, '.');
            $groups[$child][$rest] = $repository;
        }
        ksort($groups, SORT_STRING);
        $children = [];
        foreach ($groups as $child => $descendants) {
            $folder = self::fromRepositories((string) $child, $descendants);
            if ($folder !== null) {
                $children[] = $folder;
            }
        }

        return new self($name, $status, $children);
    }

    /** @return array<string, RepositoryStatus> */
    public function changedRepositories(string $relative = '.'): array
    {
        $repositories = [];
        if ($this->status !== null && $this->status->changedFiles > 0) {
            $repositories[$relative] = $this->status;
        }

        foreach ($this->children as $child) {
            $path = $relative === '.' ? $child->name : $relative.'/'.$child->name;
            $repositories += $child->changedRepositories($path);
        }

        return $repositories;
    }

    public function repositoryCount(): int
    {
        $count = $this->status !== null ? 1 : 0;
        foreach ($this->children as $child) {
            $count += $child->repositoryCount();
        }

        return $count;
    }

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
