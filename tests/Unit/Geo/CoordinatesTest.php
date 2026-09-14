<?php

namespace Tests\Unit\Geo;

use App\Geo\Support\Coordinates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Fork feature: geo feed. See docs/fork/GEO_FEED.md
 */
class CoordinatesTest extends TestCase
{
    #[Test]
    public function it_validates_ranges(): void
    {
        $this->assertTrue(Coordinates::isValid(51.5, -0.12));
        $this->assertTrue(Coordinates::isValid(-90, 180));
        $this->assertTrue(Coordinates::isValid(90, -180));

        $this->assertFalse(Coordinates::isValid(90.1, 0));
        $this->assertFalse(Coordinates::isValid(0, 180.1));
        $this->assertFalse(Coordinates::isValid('north', 0));
        $this->assertFalse(Coordinates::isValid(null, null));
    }

    #[Test]
    public function it_rejects_null_island_but_not_the_axes(): void
    {
        $this->assertFalse(Coordinates::isValid(0, 0));

        // A point on the equator or the prime meridian is a real place; only
        // the intersection of both is the "no fix" sentinel.
        $this->assertTrue(Coordinates::isValid(0, 32.5811));
        $this->assertTrue(Coordinates::isValid(51.477928, 0));
    }

    #[Test]
    public function it_measures_known_distances(): void
    {
        // London to Paris, ~344km great circle.
        $this->assertEqualsWithDelta(
            344,
            Coordinates::distance(51.5074, -0.1278, 48.8566, 2.3522),
            3
        );

        // Sydney to Los Angeles, ~12070km, checks the long-haul case where a
        // flat-earth approximation would fall apart.
        $this->assertEqualsWithDelta(
            12070,
            Coordinates::distance(-33.8688, 151.2093, 34.0522, -118.2437),
            60
        );

        $this->assertSame(0.0, Coordinates::distance(51.5, -0.12, 51.5, -0.12));
    }

    #[Test]
    public function bounding_box_contains_the_radius(): void
    {
        [$minLat, $minLng, $maxLat, $maxLng] = Coordinates::boundingBox(51.5, -0.12, 50);

        $this->assertLessThan(51.5, $minLat);
        $this->assertGreaterThan(51.5, $maxLat);
        $this->assertLessThan(-0.12, $minLng);
        $this->assertGreaterThan(-0.12, $maxLng);

        // A point 49km due north has to fall inside a 50km box.
        $this->assertGreaterThan(51.5 + (49 / 111), $maxLat);
    }

    #[Test]
    public function bounding_box_widens_towards_the_poles(): void
    {
        // A degree of longitude is ~111km at the equator and ~2km at 89°N, so
        // the same radius needs a far wider box up there.
        $equator = Coordinates::boundingBox(0, 0, 100);
        $arctic = Coordinates::boundingBox(80, 0, 100);

        $equatorWidth = $equator[3] - $equator[1];
        $arcticWidth = $arctic[3] - $arctic[1];

        $this->assertGreaterThan($equatorWidth * 4, $arcticWidth);
    }

    #[Test]
    public function bounding_box_stays_within_valid_coordinates(): void
    {
        // Near the pole the cosine term explodes; the box must still be a
        // legal set of coordinates rather than latitude 130.
        [$minLat, $minLng, $maxLat, $maxLng] = Coordinates::boundingBox(89.9, 179.9, 500);

        $this->assertGreaterThanOrEqual(-90.0, $minLat);
        $this->assertLessThanOrEqual(90.0, $maxLat);
        $this->assertGreaterThanOrEqual(-180.0, $minLng);
        $this->assertLessThanOrEqual(180.0, $maxLng);
    }

    #[Test]
    public function cluster_cells_halve_with_each_zoom_level(): void
    {
        $this->assertEqualsWithDelta(
            Coordinates::clusterCellSize(5) / 2,
            Coordinates::clusterCellSize(6),
            0.000001
        );

        $this->assertGreaterThan(0, Coordinates::clusterCellSize(20));

        // Out-of-range zooms clamp rather than producing a zero or infinite
        // cell size, which would make the grouping expression divide by zero.
        $this->assertSame(Coordinates::clusterCellSize(0), Coordinates::clusterCellSize(-5));
        $this->assertSame(Coordinates::clusterCellSize(20), Coordinates::clusterCellSize(99));
    }

    #[Test]
    public function it_parses_a_pasted_coordinate_pair(): void
    {
        // The separators a map's "copy coordinates" actually produces.
        $this->assertSame([51.5074, -0.1278], Coordinates::parsePair('51.5074, -0.1278'));
        $this->assertSame([51.5074, -0.1278], Coordinates::parsePair('51.5074,-0.1278'));
        $this->assertSame([51.5074, -0.1278], Coordinates::parsePair('51.5074 -0.1278'));
        $this->assertSame([51.5074, -0.1278], Coordinates::parsePair("  51.5074 ,  -0.1278\t"));

        $this->assertSame([-33.8688, 151.2093], Coordinates::parsePair('-33.8688, 151.2093'));
        $this->assertSame([-90.0, 180.0], Coordinates::parsePair('-90, 180'));
    }

    #[Test]
    #[DataProvider('notCoordinatePairs')]
    public function it_rejects_anything_that_is_not_a_pair(string $value): void
    {
        // Returning null is what lets the caller treat the text as a place
        // name instead, so a false positive here is a search that silently
        // jumps to the middle of the ocean.
        $this->assertNull(Coordinates::parsePair($value));
    }

    public static function notCoordinatePairs(): array
    {
        return [
            'empty' => [''],
            'place name' => ['London'],
            'place and country' => ['Lyon, France'],
            'half a pair' => ['51.5074'],
            'three numbers' => ['51.5074, -0.1278, 12'],
            'latitude out of range' => ['91, 0'],
            'longitude out of range' => ['51.5, 181'],
            'null island' => ['0, 0'],
            'semicolon' => ['51.5074; -0.1278'],
            'degrees and minutes' => ["51°30'N 0°7'W"],
            'trailing text' => ['51.5074, -0.1278 London'],
            'street address' => ['10 Downing Street'],
        ];
    }
}
