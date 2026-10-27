<?php

namespace App\Immich\Support;

/**
 * Fork feature: Immich library. See docs/fork/IMMICH.md
 *
 * Request bodies for Immich's search endpoints, which is where its API has
 * moved most between versions.
 *
 * Immich 3.2 replaced the flat search fields (`type`, `albumIds`, `page`,
 * `order`...) with a `filter` tree, `orderBy` and cursor paging, deprecated
 * the flat ones, and refuses a body that mixes the two. So:
 *
 * - The library listing speaks the new dialect to 3.2 and later, and the flat
 *   one to anything older. The flat one is what goes first when Immich drops
 *   it, so the newer servers are kept off it.
 * - Smart search speaks the flat dialect everywhere. In 3.2 it has `filter`
 *   but no cursor, and `page` cannot be combined with `filter` — the flat
 *   form is the only one that can page. Revisit when Immich gives smart
 *   search a cursor.
 *
 * The page token handed to the browser is opaque and says which kind it is:
 * `c:<cursor>` or `p:<page number>`.
 */
final class SearchRequest
{
    /**
     * Body for `POST /api/search/metadata`: newest first.
     *
     * @return array<string, mixed>
     */
    public static function library(?ServerVersion $version, ?string $albumId, ?string $token, int $size, bool $videos): array
    {
        $size = max(1, min(1000, $size));

        if ($version && $version->atLeast(3, 2)) {
            $filter = [
                'type' => $videos ? ['in' => ['IMAGE', 'VIDEO']] : ['eq' => 'IMAGE'],
                'visibility' => ['eq' => 'timeline'],
            ];

            if ($albumId) {
                $filter['albumIds'] = ['any' => [$albumId]];
            }

            $body = [
                'filter' => $filter,
                'orderBy' => ['field' => 'fileCreatedAt', 'direction' => 'desc'],
                'withExif' => true,
                'size' => $size,
            ];

            $cursor = self::cursorFrom($token);

            if ($cursor !== null) {
                $body['cursor'] = $cursor;
            }

            return $body;
        }

        $body = self::flatBase($version, $videos) + [
            'order' => 'desc',
            'withExif' => true,
            'size' => $size,
            'page' => self::pageFrom($token),
        ];

        if ($albumId) {
            $body['albumIds'] = [$albumId];
        }

        return $body;
    }

    /**
     * Body for `POST /api/search/smart`: Immich's CLIP search, "beach at
     * sunset" and the like. Needs machine learning enabled on the server.
     *
     * @return array<string, mixed>
     */
    public static function smart(?ServerVersion $version, string $query, ?string $token, int $size, bool $videos): array
    {
        return self::flatBase($version, $videos) + [
            'query' => $query,
            'withExif' => true,
            'size' => max(1, min(1000, $size)),
            'page' => self::pageFrom($token),
        ];
    }

    /**
     * The next page token from a search response, or null on the last page.
     *
     * @param  array<string, mixed>  $assets  The response's `assets` object
     */
    public static function nextToken(array $assets): ?string
    {
        if (! empty($assets['nextCursor']) && is_string($assets['nextCursor'])) {
            return 'c:'.$assets['nextCursor'];
        }

        // nextPage is a string ("2") on every version that has it.
        if (isset($assets['nextPage']) && ctype_digit((string) $assets['nextPage'])) {
            return 'p:'.$assets['nextPage'];
        }

        return null;
    }

    public static function isToken(?string $token): bool
    {
        return $token === null
            || $token === ''
            || (bool) preg_match('/^(c:[A-Za-z0-9+\/=_.:-]{1,512}|p:[1-9][0-9]{0,5})$/', $token);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function flatBase(?ServerVersion $version, bool $videos): array
    {
        $base = [];

        // Without `type` both images and videos come back, plus audio and
        // "other" — the controller drops those.
        if (! $videos) {
            $base['type'] = 'IMAGE';
        }

        // `visibility` replaced `isArchived` in 1.133. Before that, archived
        // photos come back too, which is harmless.
        if ($version && $version->atLeast(1, 133)) {
            $base['visibility'] = 'timeline';
        }

        return $base;
    }

    protected static function cursorFrom(?string $token): ?string
    {
        return ($token && str_starts_with($token, 'c:')) ? substr($token, 2) : null;
    }

    protected static function pageFrom(?string $token): int
    {
        return ($token && str_starts_with($token, 'p:')) ? max(1, (int) substr($token, 2)) : 1;
    }
}
