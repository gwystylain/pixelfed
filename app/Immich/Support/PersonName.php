<?php

namespace App\Immich\Support;

/**
 * Fork feature: Immich library. See docs/fork/IMMICH.md
 *
 * Mappings match on name, not on Immich's person id. One person is often
 * several face clusters in Immich that were each given the same name, and
 * "Mum" should be @mum whichever cluster a photo landed in.
 */
final class PersonName
{
    /** Longest name stored; Immich does not cap them, the column does. */
    const MAX_LENGTH = 191;

    /**
     * The form two names are compared in: trimmed, inner whitespace collapsed
     * to one space, lower-cased. "  Ann  Smith" and "ann smith" are the same
     * person; "Ann" and "Ann Smith" are not.
     */
    public static function key(?string $name): string
    {
        $name = self::clean($name);

        return $name === '' ? '' : mb_strtolower($name, 'UTF-8');
    }

    /**
     * The name as it should be displayed: whitespace tidied, case kept.
     */
    public static function clean(?string $name): string
    {
        $name = preg_replace('/\s+/u', ' ', trim((string) $name)) ?? '';

        return mb_substr($name, 0, self::MAX_LENGTH, 'UTF-8');
    }
}
