/**
 * Fork feature: geo feed. See docs/fork/GEO_FEED.md
 *
 * The composer's side of the location suggestion, kept out of upstream's
 * ComposeModal.vue so its insertions stay one line each.
 *
 * Upstream's composer keeps picked photos as `files: [{file, editState}]`
 * and only uploads them on publish, so there is nothing on the server to
 * ask about while the author is writing. Instead the coordinates are read
 * from the files in the browser, and the author's answer to the card waits
 * on the File until publish, when `applyGeoChoices` hands it to the uploads.
 *
 * A File that has been looked at carries `geo`: `{lat, lng, precision}`
 * when it knows where it was taken — `precision` null until the author
 * picks `city`, `exact` or `none` — and null when it does not. A File is
 * not a plain object, so Vue never makes this reactive.
 */
import { readGps, isValidCoordinate } from './exif-gps.js';

function enabled() {
	return !!(window.App && App.config && App.config.features && App.config.features.geo === true);
}

/**
 * Where one photo was taken. Read once per file; later calls share it.
 *
 * @param {File} file
 * @returns {Promise<{lat: number, lng: number, precision: string|null}|null>}
 */
export function photoLocation(file) {
	if (!file || typeof file !== 'object') {
		return Promise.resolve(null);
	}

	if (!file.geoLookup) {
		file.geoLookup = lookup(file).then((coords) => {
			file.geo = coords ? { lat: coords.lat, lng: coords.lng, precision: null } : null;

			return file.geo;
		});
	}

	return file.geoLookup;
}

async function lookup(file) {
	// Picked from Immich: its location is the photo's EXIF unless the owner
	// corrected it there, and a rendering Immich converted has no EXIF left.
	const immich = file.immich && file.immich.location;
	if (immich && isValidCoordinate(Number(immich.lat), Number(immich.lng))) {
		return { lat: Number(immich.lat), lng: Number(immich.lng) };
	}

	// The server only reads images, and a video is too big to slice into.
	if (/^video\//.test(file.type || '')) {
		return null;
	}

	return readGps(file);
}

/**
 * The first photo, in the composer's order, that knows where it was taken —
 * the one the server takes a post's pin from. Reads one file at a time, so
 * a big album is not all in memory at once.
 *
 * @param {Array<{file: File}>} files The composer's `files`
 * @returns {Promise<{file: File, lat: number, lng: number}|null>}
 */
export async function firstLocatedPhoto(files) {
	if (!enabled()) {
		return null;
	}

	for (const entry of (files || []).slice()) {
		const geo = entry && entry.file ? await photoLocation(entry.file) : null;

		if (geo) {
			return { file: entry.file, lat: geo.lat, lng: geo.lng };
		}
	}

	return null;
}

/**
 * Whether the author told the card not to add a location.
 *
 * @param {Array<{file: File}>} files
 */
export function geoDeclined(files) {
	return (files || []).some((entry) => entry && entry.file && entry.file.geo && entry.file.geo.precision === 'none');
}

/**
 * After upload, before publish: give the uploads the author's answer, and
 * the coordinates of the photo the pin comes from in case its upload lost
 * them — a filtered or cropped photo is re-encoded, which drops its EXIF.
 *
 * A decline has to arrive. Without it the server places the post on
 * publish anyway, so if it cannot be delivered this says so and returns
 * false, and the composer does not publish. Anything else is a nicety and
 * failing it is silent.
 *
 * @param {Array<{file: File}>} files The composer's `files`
 * @param {Array<{id: string}>} media The composer's `media`, same order
 * @returns {Promise<boolean>} false when the post must not be published
 */
export async function applyGeoChoices(files, media) {
	if (!enabled()) {
		return true;
	}

	let declined = null;
	let source = null;

	(files || []).forEach((entry, i) => {
		const geo = entry && entry.file ? entry.file.geo : null;
		const id = media && media[i] ? media[i].id : null;

		if (!geo || !id) {
			return;
		}

		if (geo.precision === 'none') {
			declined = declined || id;
		} else if (!source) {
			source = { id, geo };
		}
	});

	if (declined) {
		try {
			// `none` on any one upload takes the whole post off the map.
			await axios.put('/api/geo/v1/compose/media/' + declined, { precision: 'none' }, { timeout: 8000 });

			return true;
		} catch (e) {
			swal(
				'Location not removed',
				'Could not turn the location off for this post, so it has not been posted. Try again, or remove the location after posting.',
				'error'
			);

			return false;
		}
	}

	if (!source) {
		return true;
	}

	const body = { lat: source.geo.lat, lng: source.geo.lng };
	if (source.geo.precision) {
		body.precision = source.geo.precision;
	}

	try {
		await axios.put('/api/geo/v1/compose/media/' + source.id, body, { timeout: 8000 });
	} catch (e) {
		// The server still has whatever it read from the upload itself.
	}

	return true;
}
