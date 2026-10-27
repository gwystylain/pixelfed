<?php

namespace App\Immich\Support;

/**
 * Fork feature: Immich library. See docs/fork/IMMICH.md
 *
 * Immich ids are UUIDs. Every id that reaches an Immich URL path is checked
 * against this first, so a crafted id cannot walk the path elsewhere on the
 * server the key unlocks.
 */
final class AssetId
{
    public static function isValid(mixed $id): bool
    {
        return is_string($id)
            && (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id);
    }
}
