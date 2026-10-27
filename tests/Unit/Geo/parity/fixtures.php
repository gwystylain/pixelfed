<?php

/*
|--------------------------------------------------------------------------
| Geo feed: browser/server GPS reader parity (fork feature)
|--------------------------------------------------------------------------
|
| Writes GPS fixtures built by ExifGpsFixture, plus corrupted and junk
| variants, into a directory, with what App\Geo\Support\ExifGpsReader makes
| of each in expected.json. check.mjs then runs the browser's port,
| resources/assets/js/geo/exif-gps.js, over the same files. See "Testing" in
| docs/fork/GEO_FEED.md.
|
|   php tests/Unit/Geo/parity/fixtures.php <out-dir>
|
| Not a PHPUnit test (no Test.php suffix): it needs Node for the other half.
|
*/

use App\Geo\Support\ExifGpsReader;
use Tests\Unit\Geo\ExifGpsFixture;

$root = dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
require_once $root.'/tests/Unit/Geo/ExifGpsFixture.php';

$out = $argv[1] ?? null;
if (! $out) {
    fwrite(STDERR, "usage: php tests/Unit/Geo/parity/fixtures.php <out-dir>\n");
    exit(1);
}

@mkdir($out.'/fx', 0777, true);
mt_srand(42);

$cases = [];
$coords = [
    [51.5007, -0.1246], [-33.8568, 151.2153], [40.712776, -74.005974],
    [35.65858, 139.74543], [-22.9519, -43.2105], [0.0, 0.0],
    [89.9, 179.9], [-89.5, -179.5], [64.1466, -21.9426],
];

foreach ($coords as [$lat, $lng]) {
    foreach ([true, false] as $little) {
        $order = $little ? 'le' : 'be';
        $cases["jpeg-$order-$lat,$lng"] = ExifGpsFixture::jpeg($lat, $lng, $little);
        $cases["jpeg-alt-$order-$lat,$lng"] = ExifGpsFixture::jpeg($lat, $lng, $little, 123.5);
        $cases["heic-$order-$lat,$lng"] = ExifGpsFixture::heic($lat, $lng, $little);
        $cases["tiff-$order-$lat,$lng"] = ExifGpsFixture::tiff($lat, $lng, $little);
    }
}

$bases = array_values($cases);

// Truncated, and with bytes flipped.
for ($i = 0; $i < 400; $i++) {
    $bytes = $bases[mt_rand(0, count($bases) - 1)];

    if ($i % 2) {
        $bytes = substr($bytes, 0, mt_rand(0, strlen($bytes)));
    } else {
        for ($k = 0, $n = mt_rand(1, 6); $k < $n; $k++) {
            $bytes[mt_rand(0, strlen($bytes) - 1)] = chr(mt_rand(0, 255));
        }
    }

    $cases["corrupt-$i"] = $bytes;
}

// Junk, some of it with TIFF magic and EXIF markers sprinkled in.
for ($i = 0; $i < 100; $i++) {
    $bytes = '';
    for ($k = 0, $n = mt_rand(16, 4000); $k < $n; $k++) {
        $bytes .= chr(mt_rand(0, 255));
    }

    if ($i % 3 === 0) {
        $bytes = substr_replace($bytes, "II\x2A\x00", mt_rand(0, strlen($bytes) - 8), 4);
    }
    if ($i % 5 === 0) {
        $bytes = substr_replace($bytes, "Exif\x00\x00MM\x00\x2A", mt_rand(0, strlen($bytes) - 10), 10);
    }

    $cases["junk-$i"] = $bytes;
}

$expected = [];
$n = 0;

foreach ($cases as $name => $bytes) {
    $file = sprintf('%04d.bin', $n++);
    file_put_contents("$out/fx/$file", $bytes);

    $gps = ExifGpsReader::fromBytes($bytes);
    $expected[] = [
        'name' => $name,
        'file' => $file,
        'gps' => $gps ? ['lat' => $gps['lat'], 'lng' => $gps['lng']] : null,
    ];
}

file_put_contents("$out/expected.json", json_encode($expected));

printf("%d cases written to %s, %d with GPS\n", count($expected), $out, count(array_filter($expected, fn ($e) => $e['gps'])));
