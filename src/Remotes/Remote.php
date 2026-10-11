<?php

namespace Noo\CraftRemoteSync\Remotes;

readonly class Remote
{
    /** The SSH target (user@host) with any :port suffix stripped. */
    private string $sshTarget;

    private ?int $sshPort;

    public function __construct(
        public string $name,
        public string $host,
        public string $path,
        public bool $push = false,
        public ?string $phpBinary = null,
    ) {
        if (preg_match('/^(.+):(\d+)$/', $host, $matches)) {
            $this->sshTarget = $matches[1];
            $this->sshPort = (int) $matches[2];
        } else {
            $this->sshTarget = $host;
            $this->sshPort = null;
        }
    }

    /**
     * The SSH target (user@host) with any :port stripped; the port is passed via -p instead.
     */
    public function sshTarget(): string
    {
        return $this->sshTarget;
    }

    public function sshPort(): ?int
    {
        return $this->sshPort;
    }

    /**
     * The bare hostname, without a user@ prefix or :port suffix.
     */
    public function hostname(): string
    {
        return str_contains($this->sshTarget, '@')
            ? explode('@', $this->sshTarget, 2)[1]
            : $this->sshTarget;
    }
}
