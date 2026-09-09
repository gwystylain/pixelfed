<?php

namespace Tests\Unit\Geo;

/**
 * Fork feature: geo feed. See docs/fork/GEO_FEED.md
 *
 * Builds minimal files carrying a GPS EXIF block, so the reader can be
 * tested without checking binary photos into the repository.
 */
class ExifGpsFixture
{
    /**
     * A TIFF/EXIF block with nothing in it but a GPS IFD.
     *
     * Layout, with $little controlling byte order:
     *
     *   0   TIFF header, 8 bytes, IFD0 at offset 8
     *   8   IFD0: one entry, the GPS IFD pointer          (18 bytes)
     *   26  GPS IFD: lat ref, lat, lng ref, lng, [alt]
     *   ..  the rationals the GPS IFD entries point at
     */
    public static function tiff(
        float $lat,
        float $lng,
        bool $little = true,
        ?float $altitude = null
    ): string {
        $latRef = $lat < 0 ? 'S' : 'N';
        $lngRef = $lng < 0 ? 'W' : 'E';

        $gpsIfdOffset = 26;
        $entryCount = $altitude === null ? 4 : 6;
        $dataOffset = $gpsIfdOffset + 2 + ($entryCount * 12) + 4;

        $latData = self::dmsRationals(abs($lat), $little);
        $lngData = self::dmsRationals(abs($lng), $little);

        $latDataOffset = $dataOffset;
        $lngDataOffset = $latDataOffset + strlen($latData);
        $altDataOffset = $lngDataOffset + strlen($lngData);

        $entries = self::entry(0x0001, 2, 2, self::inlineAscii($latRef), $little)
            .self::entry(0x0002, 5, 3, self::long($latDataOffset, $little), $little)
            .self::entry(0x0003, 2, 2, self::inlineAscii($lngRef), $little)
            .self::entry(0x0004, 5, 3, self::long($lngDataOffset, $little), $little);

        $altData = '';
        if ($altitude !== null) {
            // GPSAltitudeRef is a single BYTE, left-aligned in the value field.
            $ref = $altitude < 0 ? "\x01" : "\x00";
            $entries .= self::entry(0x0005, 1, 1, $ref."\x00\x00\x00", $little);
            $entries .= self::entry(0x0006, 5, 1, self::long($altDataOffset, $little), $little);
            $altData = self::rational(abs($altitude), 100, $little);
        }

        $gpsIfd = self::short($entryCount, $little)
            .$entries
            .self::long(0, $little);

        $ifd0 = self::short(1, $little)
            .self::entry(0x8825, 4, 1, self::long($gpsIfdOffset, $little), $little)
            .self::long(0, $little);

        $header = ($little ? 'II' : 'MM')
            .self::short(42, $little)
            .self::long(8, $little);

        return $header.$ifd0.$gpsIfd.$latData.$lngData.$altData;
    }

    /**
     * The TIFF block wrapped in a JPEG APP1 segment, as a camera writes it.
     *
     * The scan segment is a stub but has to be present: PHP's own
     * exif_read_data() rejects a JPEG with no image data outright, and the
     * fixture is only worth having if the reference implementation agrees
     * with it.
     */
    public static function jpeg(float $lat, float $lng, bool $little = true, ?float $altitude = null): string
    {
        $tiff = self::tiff($lat, $lng, $little, $altitude);
        $payload = "Exif\x00\x00".$tiff;

        return "\xFF\xD8"                                             // SOI
            ."\xFF\xE1".pack('n', strlen($payload) + 2).$payload      // APP1
            ."\xFF\xDA\x00\x08\x01\x01\x00\x00\x3F\x00\x00"           // SOS
            ."\xFF\xD9";                                              // EOI
    }

    /**
     * A HEIC-shaped file: boxes up front, the EXIF item buried in `mdat`.
     *
     * ext-exif cannot read this at all, which is the whole reason the reader
     * does its own parsing.
     */
    public static function heic(float $lat, float $lng, bool $little = true): string
    {
        $tiff = self::tiff($lat, $lng, $little);

        $ftyp = "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00heicmif1";
        $filler = str_repeat("\x00", 4096);
        $exifItem = pack('N', 6).'Exif'."\x00\x00".$tiff;
        $mdat = pack('N', strlen($exifItem) + strlen($filler) + 8).'mdat'.$filler.$exifItem;

        return $ftyp.$mdat;
    }

    protected static function dmsRationals(float $degrees, bool $little): string
    {
        $d = (int) floor($degrees);
        $remainder = ($degrees - $d) * 60;
        $m = (int) floor($remainder);
        $s = ($remainder - $m) * 60;

        return self::rational($d, 1, $little)
            .self::rational($m, 1, $little)
            .self::rational($s, 10000, $little);
    }

    protected static function rational(float $value, int $denominator, bool $little): string
    {
        return self::long((int) round($value * $denominator), $little)
            .self::long($denominator, $little);
    }

    protected static function entry(int $tag, int $type, int $count, string $value, bool $little): string
    {
        return self::short($tag, $little)
            .self::short($type, $little)
            .self::long($count, $little)
            .$value;
    }

    protected static function inlineAscii(string $char): string
    {
        return $char."\x00\x00\x00";
    }

    protected static function short(int $value, bool $little): string
    {
        return pack($little ? 'v' : 'n', $value);
    }

    protected static function long(int $value, bool $little): string
    {
        return pack($little ? 'V' : 'N', $value);
    }
}
