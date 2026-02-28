<?php

namespace jorge\craftremotesync\models;

class RemoteConfig
{
    public function __construct(
        public string $name,
        public string $host,
        public string $path,
        public bool $pushAllowed = false,
        public bool $isAtomic = false,
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
        $clone = clone $this;
        $clone->isAtomic = $isAtomic;
        return $clone;
    }
}
