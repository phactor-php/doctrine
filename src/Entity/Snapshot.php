<?php

namespace Phactor\Doctrine\Entity;

class Snapshot
{
    private string $id;
    private string $class;
    private string $version;
    private string $snapshot;

    public function __construct(string $id, string $class, string $version, string $snapshot)
    {
        $this->id = $id;
        $this->class = $class;
        $this->version = $version;
        $this->snapshot = $snapshot;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getClass(): string
    {
        return $this->class;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getSnapshot(): string
    {
        return $this->snapshot;
    }

    public function update(int $newVersion, string $snapshot): void
    {
        if ($newVersion > $this->version) {
            $this->snapshot = $snapshot;
            $this->version = $newVersion;
        }
    }
}
