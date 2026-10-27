/**
 * Fork feature: geo feed. See docs/fork/GEO_FEED.md
 *
 * Reads GPS coordinates out of a picked file, in the browser.
 *
 * A port of app/Geo/Support/ExifGpsReader.php, cut down to latitude and
 * longitude: the composer only uploads on publish, so until then the server
 * has nothing to read. Same approach as the PHP — find the embedded TIFF
 * block ("Exif\0\0" first, bare TIFF magic as a fallback) and walk IFD0 to
 * the GPS IFD — so JPEG, HEIC, AVIF, WebP, PNG and TIFF all work without
 * knowing the container. Change one, change the other.
 *
 * Every read is bounds checked: the file is whatever the author picked.
 */

/** The default of `geo.exif.max_read_bytes`. */
const MAX_READ_BYTES = 8 * 1024 * 1024;

/** How many candidate TIFF headers to try before giving up. */
const MAX_TIFF_CANDIDATES = 8;

/** A malformed IFD can claim a huge entry count. Refuse to walk it. */
const MAX_IFD_ENTRIES = 512;

const TAG_GPS_IFD = 0x8825;
const GPS_LATITUDE_REF = 0x0001;
const GPS_LATITUDE = 0x0002;
const GPS_LONGITUDE_REF = 0x0003;
const GPS_LONGITUDE = 0x0004;

/** Bytes per TIFF field type, by type id. */
const TYPE_SIZES = { 1: 1, 2: 1, 3: 2, 4: 4, 5: 8, 6: 1, 7: 1, 8: 2, 9: 4, 10: 8, 11: 4, 12: 8 };

const EXIF_MARKER = [0x45, 0x78, 0x69, 0x66, 0x00, 0x00];
const TIFF_LITTLE = [0x49, 0x49, 0x2a, 0x00];
const TIFF_BIG = [0x4d, 0x4d, 0x00, 0x2a];

/**
 * @param {Blob} file
 * @returns {Promise<{lat: number, lng: number}|null>}
 */
export async function readGps(file) {
	if (!file || typeof file.slice !== 'function' || file.size < 16) {
		return null;
	}

	try {
		const buffer = await readBytes(file.slice(0, MAX_READ_BYTES));

		return gpsFromBytes(new Uint8Array(buffer));
	} catch (e) {
		return null;
	}
}

/**
 * @param {Uint8Array} bytes
 * @returns {{lat: number, lng: number}|null}
 */
export function gpsFromBytes(bytes) {
	for (const offset of tiffCandidates(bytes)) {
		const gps = parseTiff(bytes, offset);

		if (gps) {
			return gps;
		}
	}

	return null;
}

/**
 * Same test as App\Geo\Support\Coordinates::isValid, Null Island included:
 * cameras without a fix write 0/0 rather than leaving the tags out.
 */
export function isValidCoordinate(lat, lng) {
	if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
		return false;
	}

	if (lat < -90 || lat > 90 || lng < -180 || lng > 180) {
		return false;
	}

	return !(Math.abs(lat) < 0.0001 && Math.abs(lng) < 0.0001);
}

function readBytes(blob) {
	if (typeof blob.arrayBuffer === 'function') {
		return blob.arrayBuffer();
	}

	return new Promise((resolve, reject) => {
		const reader = new FileReader();
		reader.onload = () => resolve(reader.result);
		reader.onerror = () => reject(reader.error);
		reader.readAsArrayBuffer(blob);
	});
}

function indexOf(bytes, pattern, from) {
	const last = bytes.length - pattern.length;

	for (let i = bytes.indexOf(pattern[0], from); i !== -1 && i <= last; i = bytes.indexOf(pattern[0], i + 1)) {
		let j = 1;

		while (j < pattern.length && bytes[i + j] === pattern[j]) {
			j++;
		}

		if (j === pattern.length) {
			return i;
		}
	}

	return -1;
}

function isTiffHeader(bytes, offset) {
	return [TIFF_LITTLE, TIFF_BIG].some((magic) => magic.every((b, i) => bytes[offset + i] === b));
}

/**
 * Offsets that look like the start of a TIFF header. "Exif\0\0" markers come
 * first because they are unambiguous; bare TIFF magic can false positive on
 * arbitrary binary data, which is why each candidate is tried in turn.
 */
function tiffCandidates(bytes) {
	const candidates = [];
	const seen = new Set();
	let from = 0;
	let pos;

	while ((pos = indexOf(bytes, EXIF_MARKER, from)) !== -1) {
		const tiffAt = pos + 6;

		if (tiffAt + 8 <= bytes.length && isTiffHeader(bytes, tiffAt)) {
			candidates.push(tiffAt);
			seen.add(tiffAt);
		}

		from = pos + 6;

		if (candidates.length >= MAX_TIFF_CANDIDATES) {
			return candidates;
		}
	}

	for (const magic of [TIFF_LITTLE, TIFF_BIG]) {
		from = 0;

		while ((pos = indexOf(bytes, magic, from)) !== -1) {
			if (!seen.has(pos) && pos + 8 <= bytes.length) {
				candidates.push(pos);
				seen.add(pos);
			}

			from = pos + 2;

			if (candidates.length >= MAX_TIFF_CANDIDATES) {
				return candidates;
			}
		}
	}

	return candidates;
}

function parseTiff(bytes, base) {
	// TIFF offsets count from the start of the TIFF header, not the file.
	const view = new DataView(bytes.buffer, bytes.byteOffset + base, bytes.length - base);
	const order = String.fromCharCode(bytes[base], bytes[base + 1]);

	if (order !== 'II' && order !== 'MM') {
		return null;
	}

	const tiff = reader(view, order === 'II');

	const ifd0 = tiff.long(4);
	if (ifd0 === null || ifd0 < 8 || ifd0 >= tiff.length) {
		return null;
	}

	let gpsOffset = null;
	for (const entry of readIfd(tiff, ifd0)) {
		if (entry.tag === TAG_GPS_IFD) {
			gpsOffset = entryFirstInt(tiff, entry);
			break;
		}
	}

	if (gpsOffset === null || gpsOffset < 8 || gpsOffset >= tiff.length) {
		return null;
	}

	const gps = {};
	for (const entry of readIfd(tiff, gpsOffset)) {
		gps[entry.tag] = entry;
	}

	let lat = degrees(tiff, gps[GPS_LATITUDE]);
	let lng = degrees(tiff, gps[GPS_LONGITUDE]);

	if (lat === null || lng === null) {
		return null;
	}

	if ((ascii(tiff, gps[GPS_LATITUDE_REF]) || 'N').toUpperCase() === 'S') {
		lat = -lat;
	}

	if ((ascii(tiff, gps[GPS_LONGITUDE_REF]) || 'E').toUpperCase() === 'W') {
		lng = -lng;
	}

	if (!isValidCoordinate(lat, lng)) {
		return null;
	}

	return {
		lat: Math.round(lat * 1e7) / 1e7,
		lng: Math.round(lng * 1e7) / 1e7,
	};
}

function reader(view, little) {
	const length = view.byteLength;

	const fits = (offset, size) => offset >= 0 && offset + size <= length;

	return {
		length,
		view,
		byte: (offset) => (fits(offset, 1) ? view.getUint8(offset) : null),
		short: (offset) => (fits(offset, 2) ? view.getUint16(offset, little) : null),
		long: (offset) => (fits(offset, 4) ? view.getUint32(offset, little) : null),
		rational(offset) {
			const numerator = this.long(offset);
			const denominator = this.long(offset + 4);

			if (numerator === null || denominator === null || denominator === 0) {
				return null;
			}

			return numerator / denominator;
		},
	};
}

function readIfd(tiff, offset) {
	let count = tiff.short(offset);
	if (count === null || count === 0) {
		return [];
	}

	count = Math.min(count, MAX_IFD_ENTRIES);
	const entries = [];

	for (let i = 0; i < count; i++) {
		const entryOffset = offset + 2 + i * 12;
		if (entryOffset + 12 > tiff.length) {
			break;
		}

		const tag = tiff.short(entryOffset);
		const type = tiff.short(entryOffset + 2);
		const valueCount = tiff.long(entryOffset + 4);

		if (tag === null || type === null || valueCount === null) {
			break;
		}

		entries.push({ tag, type, count: valueCount, offset: entryOffset + 8 });
	}

	return entries;
}

/**
 * Where an entry's values live: four bytes or fewer are inlined into the
 * entry, anything larger is stored elsewhere behind an offset.
 */
function valueOffset(tiff, entry) {
	const size = TYPE_SIZES[entry.type];
	if (!size || entry.count < 1) {
		return null;
	}

	const total = size * entry.count;
	if (total > 0x7fffffff) {
		return null;
	}

	if (total <= 4) {
		return entry.offset;
	}

	const pointer = tiff.long(entry.offset);
	if (pointer === null || pointer < 8 || pointer + total > tiff.length) {
		return null;
	}

	return pointer;
}

/** First value of a SHORT/LONG entry, used for the GPS IFD pointer. */
function entryFirstInt(tiff, entry) {
	if (![3, 4, 9].includes(entry.type)) {
		return null;
	}

	const offset = valueOffset(tiff, entry);
	if (offset === null) {
		return null;
	}

	return entry.type === 3 ? tiff.short(offset) : tiff.long(offset);
}

/** GPSLatitude/GPSLongitude are three RATIONALs: degrees, minutes, seconds. */
function degrees(tiff, entry) {
	if (!entry || entry.type !== 5 || entry.count < 3) {
		return null;
	}

	const offset = valueOffset(tiff, entry);
	if (offset === null) {
		return null;
	}

	const parts = [];
	for (let i = 0; i < 3; i++) {
		const value = tiff.rational(offset + i * 8);
		if (value === null) {
			return null;
		}
		parts.push(value);
	}

	const [deg, min, sec] = parts;

	return deg + min / 60 + sec / 3600;
}

function ascii(tiff, entry) {
	if (!entry || entry.type !== 2 || entry.count < 1) {
		return null;
	}

	const offset = valueOffset(tiff, entry);
	if (offset === null) {
		return null;
	}

	let text = '';
	for (let i = 0; i < Math.min(entry.count, 8); i++) {
		const b = tiff.byte(offset + i);
		if (b === null) {
			break;
		}
		text += String.fromCharCode(b);
	}

	return text.replace(/^[\x00 \t\r\n]+|[\x00 \t\r\n]+$/g, '') || null;
}
