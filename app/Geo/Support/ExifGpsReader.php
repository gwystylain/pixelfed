<?php

namespace App\Geo\Support;

/**
 * Fork feature: geo feed. See docs/fork/GEO_FEED.md
 *
 * Reads GPS coordinates out of an image's EXIF block.
 *
 * This does not use ext-exif. `exif_read_data()` cannot parse HEIC/HEIF,
 * which is the default capture format on every recent iPhone and therefore
 * the single most common source of photo GPS we will see. Instead we locate
 * the embedded TIFF block — the container stops mattering once you have
 * found it — and walk the GPS IFD directly.
 *
 * Containers this covers:
 *   JPEG   APP1 segment, "Exif\0\0" + TIFF
 *   HEIC   `Exif` item in mdat, with or without a prefix
 *   AVIF   as HEIC
 *   WebP   "EXIF" chunk
 *   PNG    "eXIf" chunk, bare TIFF
 *   TIFF   the file itself
 *
 * Every read is bounds checked. The input is an untrusted upload.
 */
class ExifGpsReader
{
    /** IFD0 tag pointing at the GPS sub-IFD. */
    const TAG_GPS_IFD = 0x8825;

    const GPS_LATITUDE_REF = 0x0001;

    const GPS_LATITUDE = 0x0002;

    const GPS_LONGITUDE_REF = 0x0003;

    const GPS_LONGITUDE = 0x0004;

    const GPS_ALTITUDE_REF = 0x0005;

    const GPS_ALTITUDE = 0x0006;

    /** Bytes per TIFF field type, indexed by type id. */
    const TYPE_SIZES = [
        1 => 1,  // BYTE
        2 => 1,  // ASCII
        3 => 2,  // SHORT
        4 => 4,  // LONG
        5 => 8,  // RATIONAL
        6 => 1,  // SBYTE
        7 => 1,  // UNDEFINED
        8 => 2,  // SSHORT
        9 => 4,  // SLONG
        10 => 8, // SRATIONAL
        11 => 4, // FLOAT
        12 => 8, // DOUBLE
    ];

    /** A malformed IFD can claim a huge entry count. Refuse to walk it. */
    const MAX_IFD_ENTRIES = 512;

    /** How many candidate TIFF headers to try before giving up. */
    const MAX_TIFF_CANDIDATES = 8;

    /**
     * @return array{lat: float, lng: float, altitude: float|null}|null
     */
    public static function fromFile(string $path, ?int $maxBytes = null): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $size = filesize($path);
        if ($size === false || $size < 16) {
            return null;
        }

        $length = $maxBytes === null ? $size : min($size, $maxBytes);

        $bytes = @file_get_contents($path, false, null, 0, $length);
        if ($bytes === false) {
            return null;
        }

        return self::fromBytes($bytes);
    }

    /**
     * @return array{lat: float, lng: float, altitude: float|null}|null
     */
    public static function fromBytes(string $bytes): ?array
    {
        foreach (self::tiffCandidates($bytes) as $offset) {
            $gps = self::parseTiff($bytes, $offset);

            if ($gps !== null) {
                return $gps;
            }
        }

        return null;
    }

    /**
     * Offsets within $bytes that look like the start of a TIFF header.
     *
     * "Exif\0\0" markers come first because they are unambiguous; bare TIFF
     * magic is a fallback and can false positive on arbitrary binary data,
     * which is why the caller tries each candidate in turn.
     *
     * @return list<int>
     */
    protected static function tiffCandidates(string $bytes): array
    {
        $candidates = [];
        $length = strlen($bytes);
        $seen = [];

        $offset = 0;
        while (($pos = strpos($bytes, "Exif\x00\x00", $offset)) !== false) {
            $tiffAt = $pos + 6;
            if ($tiffAt + 8 <= $length && self::isTiffHeader($bytes, $tiffAt)) {
                $candidates[] = $tiffAt;
                $seen[$tiffAt] = true;
            }
            $offset = $pos + 6;

            if (count($candidates) >= self::MAX_TIFF_CANDIDATES) {
                return $candidates;
            }
        }

        foreach (["II\x2A\x00", "MM\x00\x2A"] as $magic) {
            $offset = 0;
            while (($pos = strpos($bytes, $magic, $offset)) !== false) {
                if (! isset($seen[$pos]) && $pos + 8 <= $length) {
                    $candidates[] = $pos;
                    $seen[$pos] = true;
                }
                $offset = $pos + 2;

                if (count($candidates) >= self::MAX_TIFF_CANDIDATES) {
                    return $candidates;
                }
            }
        }

        return $candidates;
    }

    protected static function isTiffHeader(string $bytes, int $offset): bool
    {
        $header = substr($bytes, $offset, 4);

        return $header === "II\x2A\x00" || $header === "MM\x00\x2A";
    }

    /**
     * @return array{lat: float, lng: float, altitude: float|null}|null
     */
    protected static function parseTiff(string $bytes, int $base): ?array
    {
        $byteOrder = substr($bytes, $base, 2);
        if ($byteOrder !== 'II' && $byteOrder !== 'MM') {
            return null;
        }

        $little = $byteOrder === 'II';

        // TIFF offsets are measured from the start of the TIFF header, not
        // the start of the file, so work on a slice from $base onwards.
        $tiff = substr($bytes, $base);
        $tiffLength = strlen($tiff);

        $ifd0Offset = self::readLong($tiff, 4, $little);
        if ($ifd0Offset === null || $ifd0Offset < 8 || $ifd0Offset >= $tiffLength) {
            return null;
        }

        $gpsOffset = null;
        foreach (self::readIfd($tiff, $ifd0Offset, $little) as $entry) {
            if ($entry['tag'] === self::TAG_GPS_IFD) {
                $gpsOffset = self::entryFirstInt($tiff, $entry, $little);
                break;
            }
        }

        if ($gpsOffset === null || $gpsOffset < 8 || $gpsOffset >= $tiffLength) {
            return null;
        }

        $gps = [];
        foreach (self::readIfd($tiff, $gpsOffset, $little) as $entry) {
            $gps[$entry['tag']] = $entry;
        }

        $lat = self::degreesFromEntry($tiff, $gps[self::GPS_LATITUDE] ?? null, $little);
        $lng = self::degreesFromEntry($tiff, $gps[self::GPS_LONGITUDE] ?? null, $little);

        if ($lat === null || $lng === null) {
            return null;
        }

        $latRef = strtoupper(self::asciiFromEntry($tiff, $gps[self::GPS_LATITUDE_REF] ?? null, $little) ?? 'N');
        $lngRef = strtoupper(self::asciiFromEntry($tiff, $gps[self::GPS_LONGITUDE_REF] ?? null, $little) ?? 'E');

        if ($latRef === 'S') {
            $lat = -$lat;
        }
        if ($lngRef === 'W') {
            $lng = -$lng;
        }

        if (! Coordinates::isValid($lat, $lng)) {
            return null;
        }

        return [
            'lat' => round($lat, 7),
            'lng' => round($lng, 7),
            'altitude' => self::altitudeFromEntries($tiff, $gps, $little),
        ];
    }

    /**
     * @return list<array{tag: int, type: int, count: int, offset: int}>
     */
    protected static function readIfd(string $tiff, int $offset, bool $little): array
    {
        $count = self::readShort($tiff, $offset, $little);
        if ($count === null || $count === 0) {
            return [];
        }

        $count = min($count, self::MAX_IFD_ENTRIES);
        $entries = [];

        for ($i = 0; $i < $count; $i++) {
            $entryOffset = $offset + 2 + ($i * 12);
            if ($entryOffset + 12 > strlen($tiff)) {
                break;
            }

            $tag = self::readShort($tiff, $entryOffset, $little);
            $type = self::readShort($tiff, $entryOffset + 2, $little);
            $valueCount = self::readLong($tiff, $entryOffset + 4, $little);

            if ($tag === null || $type === null || $valueCount === null) {
                break;
            }

            $entries[] = [
                'tag' => $tag,
                'type' => $type,
                'count' => $valueCount,
                'offset' => $entryOffset + 8,
            ];
        }

        return $entries;
    }

    /**
     * Resolve where an entry's values actually live.
     *
     * Values of four bytes or fewer are inlined into the entry itself;
     * anything larger is stored elsewhere and the entry holds an offset.
     */
    protected static function valueOffset(string $tiff, array $entry, bool $little): ?int
    {
        $size = self::TYPE_SIZES[$entry['type']] ?? null;
        if ($size === null || $entry['count'] < 1) {
            return null;
        }

        $total = $size * $entry['count'];
        if ($total > 0x7FFFFFFF) {
            return null;
        }

        if ($total <= 4) {
            return $entry['offset'];
        }

        $pointer = self::readLong($tiff, $entry['offset'], $little);
        if ($pointer === null || $pointer < 8 || $pointer + $total > strlen($tiff)) {
            return null;
        }

        return $pointer;
    }

    /**
     * First value of a SHORT/LONG entry, used for the GPS IFD pointer.
     */
    protected static function entryFirstInt(string $tiff, array $entry, bool $little): ?int
    {
        if (! in_array($entry['type'], [3, 4, 9], true)) {
            return null;
        }

        $offset = self::valueOffset($tiff, $entry, $little);
        if ($offset === null) {
            return null;
        }

        return $entry['type'] === 3
            ? self::readShort($tiff, $offset, $little)
            : self::readLong($tiff, $offset, $little);
    }

    /**
     * GPSLatitude/GPSLongitude are three RATIONALs: degrees, minutes, seconds.
     */
    protected static function degreesFromEntry(string $tiff, ?array $entry, bool $little): ?float
    {
        if ($entry === null || $entry['type'] !== 5 || $entry['count'] < 3) {
            return null;
        }

        $offset = self::valueOffset($tiff, $entry, $little);
        if ($offset === null) {
            return null;
        }

        $parts = [];
        for ($i = 0; $i < 3; $i++) {
            $value = self::readRational($tiff, $offset + ($i * 8), $little);
            if ($value === null) {
                return null;
            }
            $parts[] = $value;
        }

        [$degrees, $minutes, $seconds] = $parts;

        if ($degrees < 0 || $minutes < 0 || $seconds < 0) {
            return null;
        }

        return $degrees + ($minutes / 60) + ($seconds / 3600);
    }

    protected static function asciiFromEntry(string $tiff, ?array $entry, bool $little): ?string
    {
        if ($entry === null || $entry['type'] !== 2 || $entry['count'] < 1) {
            return null;
        }

        $offset = self::valueOffset($tiff, $entry, $little);
        if ($offset === null) {
            return null;
        }

        $raw = substr($tiff, $offset, min($entry['count'], 8));

        return trim($raw, "\x00 \t\r\n") ?: null;
    }

    protected static function altitudeFromEntries(string $tiff, array $gps, bool $little): ?float
    {
        $entry = $gps[self::GPS_ALTITUDE] ?? null;
        if ($entry === null || $entry['type'] !== 5) {
            return null;
        }

        $offset = self::valueOffset($tiff, $entry, $little);
        if ($offset === null) {
            return null;
        }

        $altitude = self::readRational($tiff, $offset, $little);
        if ($altitude === null) {
            return null;
        }

        // GPSAltitudeRef 1 means the value is metres *below* sea level.
        $refEntry = $gps[self::GPS_ALTITUDE_REF] ?? null;
        if ($refEntry !== null) {
            $refOffset = self::valueOffset($tiff, $refEntry, $little);
            if ($refOffset !== null && $refOffset < strlen($tiff) && ord($tiff[$refOffset]) === 1) {
                $altitude = -$altitude;
            }
        }

        return round($altitude, 2);
    }

    protected static function readShort(string $tiff, int $offset, bool $little): ?int
    {
        if ($offset < 0 || $offset + 2 > strlen($tiff)) {
            return null;
        }

        $unpacked = unpack($little ? 'v' : 'n', substr($tiff, $offset, 2));

        return $unpacked === false ? null : $unpacked[1];
    }

    protected static function readLong(string $tiff, int $offset, bool $little): ?int
    {
        if ($offset < 0 || $offset + 4 > strlen($tiff)) {
            return null;
        }

        $unpacked = unpack($little ? 'V' : 'N', substr($tiff, $offset, 4));

        return $unpacked === false ? null : $unpacked[1];
    }

    protected static function readRational(string $tiff, int $offset, bool $little): ?float
    {
        $numerator = self::readLong($tiff, $offset, $little);
        $denominator = self::readLong($tiff, $offset + 4, $little);

        if ($numerator === null || $denominator === null || $denominator === 0) {
            return null;
        }

        return $numerator / $denominator;
    }
}
