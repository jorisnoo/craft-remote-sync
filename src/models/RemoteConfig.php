<?php

namespace Noo\CraftRemoteSync\models;

class RemoteConfig
{
    public function __construct(
        public readonly string $name,
        public readonly string $host,
        public readonly string $path,
        public readonly bool $pushAllowed = false,
        public readonly bool $isAtomic = false,
    ) {}

    public function workingPath(): string
    {
        return $this->isAtomic ? $this->path . '/current' : $this->path;
    }

    public function storagePath(): string
    {
        return $this->workingPath() . '/storage';
    }

    public function withAtomic(bool $isAtomic): self
    {
        return new self(
            name: $this->name,
            host: $this->host,
            path: $this->path,
            pushAllowed: $this->pushAllowed,
            isAtomic: $isAtomic,
        );
    }
}
