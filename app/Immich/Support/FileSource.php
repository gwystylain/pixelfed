<?php

namespace App\Immich\Support;

/**
 * Fork feature: Immich library. See docs/fork/IMMICH.md
 *
 * Which of Immich's copies of an asset to hand the composer.
 *
 * The original is always preferred: it is the full photo, and it still has
 * its EXIF, so the geo feature reads the GPS out of it exactly as it would
 * from a file picked off a disk. It is only passed over when this instance
 * would refuse it at upload anyway — a HEIC from an iPhone where MEDIA_TYPES
 * has no image/heic, a RAW file, or a 40 MB panorama over MAX_PHOTO_SIZE.
 * Then Immich's own JPEG rendering stands in: `fullsize` where the server
 * has one, `preview` (1440px) where it has not.
 *
 * Asking for `fullsize` is safe on any server that knows the size: Immich
 * answers it with the original when the original is web friendly, the
 * full-size rendering when it has generated one, and the preview otherwise.
 * Servers from before `fullsize` existed reject it, and `preview` follows.
 *
 * The candidates are only a plan. The controller still checks what each one
 * actually turned out to be, because Immich's renderings can be configured
 * as WebP, which an instance may not accept either.
 */
final class FileSource
{
    const ORIGINAL = 'original';

    const FULLSIZE = 'fullsize';

    const PREVIEW = 'preview';

    const PLAYBACK = 'playback';

    /**
     * Variants to try, best first. Empty means nothing this instance would
     * accept can come out of the asset.
     *
     * @param  string  $type  Immich asset type: IMAGE, VIDEO, ...
     * @param  string|null  $mime  originalMimeType, when Immich reported it
     * @param  int|null  $bytes  Size of the original, when known
     * @param  array<int, string>  $accepted  This instance's MEDIA_TYPES
     * @param  int  $maxBytes  This instance's MAX_PHOTO_SIZE, in bytes
     * @return array<int, string>
     */
    public static function candidates(string $type, ?string $mime, ?int $bytes, array $accepted, int $maxBytes): array
    {
        $accepted = self::normalizeList($accepted);
        $originalFits = self::acceptable($mime, $bytes, $accepted, $maxBytes);

        switch (strtoupper($type)) {
            case 'IMAGE':
                $out = $originalFits ? [self::ORIGINAL] : [];

                // Immich renders previews as JPEG or WebP; one of those has
                // to be acceptable for a stand-in to be worth fetching.
                if (array_intersect(['image/jpeg', 'image/webp'], $accepted)) {
                    $out[] = self::FULLSIZE;
                    $out[] = self::PREVIEW;
                }

                return $out;

            case 'VIDEO':
                if (! in_array('video/mp4', $accepted, true)) {
                    return [];
                }

                // Playback is Immich's transcode — H.264 MP4 under the default
                // policy, or the original when no transcode was needed.
                return $originalFits ? [self::ORIGINAL, self::PLAYBACK] : [self::PLAYBACK];
        }

        return [];
    }

    /**
     * Whether a file of this type and size would pass the composer's own
     * checks. Unknown size counts as fitting: the download is capped anyway.
     *
     * @param  array<int, string>  $accepted
     */
    public static function acceptable(?string $mime, ?int $bytes, array $accepted, int $maxBytes): bool
    {
        $mime = self::normalizeMime($mime);

        if ($mime === '' || ! in_array($mime, self::normalizeList($accepted), true)) {
            return false;
        }

        return $bytes === null || $maxBytes <= 0 || $bytes <= $maxBytes;
    }

    /**
     * The file name to give a download, with its extension changed when the
     * file is a rendering rather than the original — a HEIC name on JPEG bytes
     * would only confuse whatever reads the name next.
     */
    public static function filename(?string $original, string $mime): string
    {
        $original = trim((string) $original);
        $base = $original === '' ? 'immich' : pathinfo($original, PATHINFO_FILENAME);
        $base = preg_replace('/[^\pL\pN ._()-]+/u', '_', $base) ?: 'immich';
        $base = mb_substr($base, 0, 120, 'UTF-8');

        $ext = match (self::normalizeMime($mime)) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            'image/heic' => 'heic',
            'image/heif' => 'heif',
            'video/mp4' => 'mp4',
            default => strtolower(pathinfo($original, PATHINFO_EXTENSION)) ?: 'bin',
        };

        return $base.'.'.$ext;
    }

    public static function normalizeMime(?string $mime): string
    {
        $mime = strtolower(trim(explode(';', (string) $mime)[0]));

        // Pixelfed lists both spellings; the browser only ever sends one.
        return $mime === 'image/jpg' ? 'image/jpeg' : $mime;
    }

    /**
     * @param  array<int, string>|string  $list
     * @return array<int, string>
     */
    public static function normalizeList(array|string $list): array
    {
        if (is_string($list)) {
            $list = explode(',', $list);
        }

        return array_values(array_unique(array_filter(array_map([self::class, 'normalizeMime'], $list))));
    }
}
