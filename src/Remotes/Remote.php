<?php

namespace Noo\CraftRemoteSync\Remotes;

use InvalidArgumentException;

readonly class Remote
{
    private string $sshTarget;

    private ?int $sshPort;

    public function __construct(
        public string $name,
        public string $host,
        public string $path,
        public bool $push = false,
        public ?string $phpBinary = null,
    ) {
        // Both the full target and the part after user@ must be operands,
        // never options (including when a Remote is constructed directly).
        // Keep host operands unambiguous to both transports: colons delimit a
        // numeric port (or a bracketed IPv6 address), never an rsync module.
        $user = '(?:(?!-)[\pL\pN._+@\\\\$-]+@)?';
        $hostname = '(?:(?!-)[\pL\pN._+-]+|\[[0-9a-f:.]+(?:%[\w.-]+)?\])';
        if (! preg_match("/\\A({$user}{$hostname})(?::([0-9]+))?\\z/iu", $host, $matches)) {
            throw new InvalidArgumentException("Remote '{$name}' has an invalid SSH host. Use [user@]hostname[:port].");
        }

        $this->sshTarget = $matches[1];
        if (isset($matches[2])) {
            $this->sshPort = (int) $matches[2];
            if ($this->sshPort < 1 || $this->sshPort > 65535) {
                throw new InvalidArgumentException("Remote '{$name}' has an invalid SSH port.");
            }
        } else {
            $this->sshPort = null;
        }
    }

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
        $at = strrpos($this->sshTarget, '@');

        return $at === false ? $this->sshTarget : substr($this->sshTarget, $at + 1);
    }
}
