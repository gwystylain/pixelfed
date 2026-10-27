<?php

namespace App\Immich\Support;

/**
 * Fork feature: Immich library. See docs/fork/IMMICH.md
 *
 * An Immich server version, as `GET /api/server/version` reports it or as
 * it is stored on the connection ("3.3.5").
 */
final class ServerVersion
{
    public function __construct(
        public readonly int $major,
        public readonly int $minor,
        public readonly int $patch,
    ) {}

    /**
     * @param  array<string, mixed>|string|null  $value
     */
    public static function parse(array|string|null $value): ?self
    {
        if (is_array($value)) {
            if (! isset($value['major'], $value['minor'])) {
                return null;
            }

            return new self((int) $value['major'], (int) $value['minor'], (int) ($value['patch'] ?? 0));
        }

        if (is_string($value) && preg_match('/^v?(\d+)\.(\d+)(?:\.(\d+))?/', trim($value), $m)) {
            return new self((int) $m[1], (int) $m[2], (int) ($m[3] ?? 0));
        }

        return null;
    }

    public function atLeast(int $major, int $minor = 0, int $patch = 0): bool
    {
        return [$this->major, $this->minor, $this->patch] >= [$major, $minor, $patch];
    }

    public function __toString(): string
    {
        return $this->major.'.'.$this->minor.'.'.$this->patch;
    }
}
