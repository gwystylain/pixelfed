<?php

namespace Tests\Unit\Geo;

use App\Geo\Support\ExifGpsReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Fork feature: geo feed. See docs/fork/GEO_FEED.md
 *
 * The reader is pure PHP with no framework dependencies, so this extends
 * PHPUnit's TestCase directly and needs neither an app nor a database.
 */
class ExifGpsReaderTest extends TestCase
{
    /** Roughly a centimetre — well inside what any camera records. */
    const TOLERANCE = 0.00002;

    public static function coordinateProvider(): array
    {
        return [
            // Both hemispheres, both byte orders. Byte order in particular is
            // easy to get right one way and wrong the other.
            'london, little endian' => [51.500729, -0.124625, true],
            'london, big endian' => [51.500729, -0.124625, false],
            'sydney, south east' => [-33.856785, 151.215292, true],
            'santiago, south west' => [-33.437830, -70.650450, true],
            'reykjavik, big endian' => [64.146580, -21.942700, false],
            'equator' => [0.0, 32.581100, true],
            'prime meridian' => [51.477928, 0.0, true],
            'antimeridian, east' => [-16.500000, 179.999000, true],
            'antimeridian, west' => [-16.500000, -179.999000, true],
        ];
    }

    #[Test]
    #[DataProvider('coordinateProvider')]
    public function it_reads_gps_from_a_jpeg(float $lat, float $lng, bool $little): void
    {
        $gps = ExifGpsReader::fromBytes(ExifGpsFixture::jpeg($lat, $lng, $little));

        $this->assertNotNull($gps);
        $this->assertEqualsWithDelta($lat, $gps['lat'], self::TOLERANCE);
        $this->assertEqualsWithDelta($lng, $gps['lng'], self::TOLERANCE);
    }

    #[Test]
    #[DataProvider('coordinateProvider')]
    public function it_reads_gps_from_a_heic_container(float $lat, float $lng, bool $little): void
    {
        // The reason this class exists: ext-exif returns false for HEIC, and
        // HEIC is the iPhone default and so the main source of photo GPS.
        $gps = ExifGpsReader::fromBytes(ExifGpsFixture::heic($lat, $lng, $little));

        $this->assertNotNull($gps);
        $this->assertEqualsWithDelta($lat, $gps['lat'], self::TOLERANCE);
        $this->assertEqualsWithDelta($lng, $gps['lng'], self::TOLERANCE);
    }

    #[Test]
    public function it_reads_a_bare_tiff_block_with_no_exif_marker(): void
    {
        // How PNG's eXIf chunk and TIFF files themselves present it.
        $gps = ExifGpsReader::fromBytes(ExifGpsFixture::tiff(40.712776, -74.005974));

        $this->assertNotNull($gps);
        $this->assertEqualsWithDelta(40.712776, $gps['lat'], self::TOLERANCE);
        $this->assertEqualsWithDelta(-74.005974, $gps['lng'], self::TOLERANCE);
    }

    #[Test]
    public function it_reads_altitude_above_and_below_sea_level(): void
    {
        $above = ExifGpsReader::fromBytes(ExifGpsFixture::jpeg(46.5581, 7.964, true, 1200.5));
        $this->assertEqualsWithDelta(1200.5, $above['altitude'], 0.02);

        // GPSAltitudeRef of 1 means the value is metres below sea level.
        $below = ExifGpsReader::fromBytes(ExifGpsFixture::jpeg(31.5, 35.5, true, -400.25));
        $this->assertEqualsWithDelta(-400.25, $below['altitude'], 0.02);
    }

    #[Test]
    public function it_returns_null_when_altitude_is_absent(): void
    {
        $gps = ExifGpsReader::fromBytes(ExifGpsFixture::jpeg(51.5, -0.12));

        $this->assertNotNull($gps);
        $this->assertNull($gps['altitude']);
    }

    #[Test]
    public function it_rejects_null_island(): void
    {
        // Cameras that fail to get a fix write 0/0 rather than omitting the
        // tags. Treating that as a real location would pin posts in the
        // Atlantic off Ghana.
        $this->assertNull(ExifGpsReader::fromBytes(ExifGpsFixture::jpeg(0.0, 0.0)));
    }

    public static function malformedProvider(): array
    {
        return [
            'empty string' => [''],
            'jpeg with no exif' => ["\xFF\xD8\xFF\xDA\x00\x08\x01\x01\x00\x00\x3F\x00\x00\xFF\xD9"],
            'exif marker, no tiff' => ["\xFF\xD8\xFF\xE1\x00\x08Exif\x00\x00\xFF\xD9"],
            'tiff header, nothing else' => ["II\x2A\x00\x08\x00\x00\x00"],
            'ifd0 offset past the end' => ["II\x2A\x00\xFF\xFF\xFF\xFF"],
            'gps pointer past the end' => [
                "II\x2A\x00\x08\x00\x00\x00\x01\x00\x25\x88\x04\x00\x01\x00\x00\x00\xFF\xFF\xFF\xFF\x00\x00\x00\x00",
            ],
        ];
    }

    #[Test]
    #[DataProvider('malformedProvider')]
    public function it_returns_null_for_malformed_input(string $bytes): void
    {
        $this->assertNull(ExifGpsReader::fromBytes($bytes));
    }

    #[Test]
    public function it_survives_arbitrary_binary_input(): void
    {
        // Uploads are untrusted. Whatever this finds, it must not throw and
        // must not run away — a malformed IFD can claim 65535 entries.
        mt_srand(20260909);

        for ($i = 0; $i < 200; $i++) {
            $bytes = '';
            for ($j = 0; $j < 2048; $j++) {
                $bytes .= chr(mt_rand(0, 255));
            }

            // Seed a plausible marker so the parser is forced down its real
            // path rather than bailing out at the first check.
            $bytes = substr($bytes, 0, 100)."Exif\x00\x00II\x2A\x00".substr($bytes, 110);

            $result = ExifGpsReader::fromBytes($bytes);

            $this->assertTrue($result === null || is_array($result));
        }
    }

    #[Test]
    public function it_reads_from_a_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'geo').'.jpg';
        file_put_contents($path, ExifGpsFixture::jpeg(35.658580, 139.745430));

        try {
            $gps = ExifGpsReader::fromFile($path);

            $this->assertNotNull($gps);
            $this->assertEqualsWithDelta(35.658580, $gps['lat'], self::TOLERANCE);
            $this->assertEqualsWithDelta(139.745430, $gps['lng'], self::TOLERANCE);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function it_returns_null_for_a_missing_file(): void
    {
        $this->assertNull(ExifGpsReader::fromFile('/definitely/not/here.jpg'));
    }

    #[Test]
    public function it_respects_the_read_limit(): void
    {
        // The HEIC fixture buries its EXIF past 4KB of filler, mirroring how
        // real HEIC stores the item inside mdat. A short read must not find
        // it — that is what makes geo.exif.max_read_bytes matter.
        $path = tempnam(sys_get_temp_dir(), 'geo').'.heic';
        file_put_contents($path, ExifGpsFixture::heic(35.658580, 139.745430));

        try {
            $this->assertNull(ExifGpsReader::fromFile($path, 512));
            $this->assertNotNull(ExifGpsReader::fromFile($path, 1048576));
        } finally {
            @unlink($path);
        }
    }
}
