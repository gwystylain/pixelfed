/**
 * Fork feature: geo feed. See "Testing" in docs/fork/GEO_FEED.md
 *
 * Runs the browser's GPS reader over the files fixtures.php wrote and
 * compares every answer with the PHP reader's. Run from the repository root:
 *
 *   node tests/Unit/Geo/parity/check.mjs <fixtures-dir>
 *
 * exif-gps.js is an ES module in a package that is not "type": "module",
 * so it is imported from its source rather than by path.
 */
import { readFileSync } from 'node:fs';

const dir = process.argv[2];
if (!dir) {
	console.error('usage: node tests/Unit/Geo/parity/check.mjs <fixtures-dir>');
	process.exit(1);
}

const source = readFileSync('resources/assets/js/geo/exif-gps.js', 'utf8');
const { readGps, gpsFromBytes } = await import('data:text/javascript,' + encodeURIComponent(source));

const same = (a, b) =>
	(a === null && b === null) || (!!a && !!b && Math.abs(a.lat - b.lat) < 1e-9 && Math.abs(a.lng - b.lng) < 1e-9);

const expected = JSON.parse(readFileSync(dir + '/expected.json', 'utf8'));
const mismatches = [];

for (const c of expected) {
	const bytes = new Uint8Array(readFileSync(dir + '/fx/' + c.file));
	const viaBlob = await readGps(new Blob([bytes]));

	if (!same(viaBlob, c.gps) || !same(gpsFromBytes(bytes), c.gps)) {
		mismatches.push({ name: c.name, php: c.gps, js: viaBlob });
	}
}

// The reader is handed views, so an offset into a larger buffer must work.
const located = expected.find((c) => c.gps);
const raw = readFileSync(dir + '/fx/' + located.file);
const padded = new Uint8Array(raw.length + 7);
padded.set(raw, 7);
if (!same(gpsFromBytes(padded.subarray(7)), located.gps)) {
	mismatches.push({ name: 'subarray of ' + located.name });
}

console.log(`${expected.length - mismatches.length}/${expected.length} agree with the PHP reader`);

if (mismatches.length) {
	console.log(JSON.stringify(mismatches.slice(0, 20), null, 1));
	process.exit(1);
}
