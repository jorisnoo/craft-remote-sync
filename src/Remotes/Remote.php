<?php

namespace Noo\CraftRemoteSync\Remotes;

readonly class Remote
{
    public function __construct(
        public string $name,
        public string $host,
        public string $path,
        public bool $push = false,
        public ?string $phpBinary = null,
    ) {}

    /**
     * The bare hostname, without a user@ prefix or :port suffix.
     */
    public function hostname(): string
    {
        $host = str_contains($this->host, '@')
            ? explode('@', $this->host, 2)[1]
            : $this->host;

        if (preg_match('/^(.+):(\d+)$/', $host, $matches)) {
            return $matches[1];
        }

        return $host;
    }
}
