/**
 * Fork feature: Immich library. See docs/fork/IMMICH.md
 *
 * The two things the composer needs from Immich, kept out of upstream's
 * ComposeModal.vue so its insertions stay one line each.
 */

/**
 * Fetch one Immich asset as a File the composer can treat exactly like one
 * picked off the disk.
 *
 * The file carries an `immich` property — the asset id, the people in it
 * and where it was taken — for the people suggestions, the location card
 * and `attachImmichUploads`. A File is not
 * a plain object, so Vue never makes it reactive; components that want the
 * people copy them out.
 *
 * @param {string} id Immich asset id
 * @returns {Promise<File>}
 */
export async function fetchImmichFile(id) {
	const info = (await axios.get('/api/immich/v1/assets/' + encodeURIComponent(id))).data;

	if (!info.importable) {
		throw new Error('This instance does not accept this kind of file.');
	}

	let res;

	try {
		res = await axios.get(info.file_url, { responseType: 'blob', timeout: 180000 });
	} catch (err) {
		throw new Error(await errorMessage(err));
	}

	const type = String(res.headers['content-type'] || res.data.type || '').split(';')[0].trim();
	const name = filenameFrom(res.headers['content-disposition']) || info.filename || 'immich';
	const taken = info.taken_at ? Date.parse(info.taken_at) : NaN;

	const file = new File([res.data], name, {
		type,
		lastModified: isNaN(taken) ? Date.now() : taken,
	});

	file.immich = {
		assetId: info.id,
		people: Array.isArray(info.people) ? info.people : [],
		// Read by the geo feature's location card (js/geo/compose.js).
		location: info.location || null,
		variant: res.headers['x-immich-variant'] || null,
	};

	return file;
}

/**
 * After upload, before publish: let the server give Immich imports the
 * coordinates Immich has for them, for photos that lost their EXIF on the
 * way — a HEIC swapped for a JPEG rendering, or anything filtered or
 * cropped. Never throws: a location is a nicety, and a post must not fail
 * over one.
 *
 * @param {Array<{file: File}>} files The composer's `files`
 * @param {Array<{id: string}>} media The composer's `media`, same order
 */
export async function attachImmichUploads(files, media) {
	if (!window.App || !App.config.features || App.config.features.immich !== true) {
		return;
	}

	try {
		const items = (files || [])
			.map((entry, i) => ({
				asset_id: entry && entry.file && entry.file.immich ? entry.file.immich.assetId : null,
				media_id: media && media[i] ? media[i].id : null,
			}))
			.filter((item) => item.asset_id && item.media_id);

		if (!items.length) {
			return;
		}

		await axios.post('/api/immich/v1/compose/attach', { items }, { timeout: 8000 });
	} catch (e) {
		// See above.
	}
}

/**
 * The message an Immich endpoint sent, including from a blob response,
 * where axios leaves the JSON error body as a Blob.
 */
export async function errorMessage(err) {
	const res = err && err.response;

	if (!res) {
		return (err && err.message) || 'Could not reach this server.';
	}

	let data = res.data;

	if (typeof Blob !== 'undefined' && data instanceof Blob) {
		try {
			data = JSON.parse(await data.text());
		} catch (e) {
			data = null;
		}
	}

	if (data && data.message) {
		return data.message;
	}

	return 'Something went wrong (' + res.status + ').';
}

function filenameFrom(header) {
	if (!header) {
		return null;
	}

	const star = /filename\*\s*=\s*(?:UTF-8|utf-8)''([^;]+)/.exec(header);

	if (star) {
		try {
			return decodeURIComponent(star[1].trim());
		} catch (e) {
			// fall through to the plain form
		}
	}

	const plain = /filename\s*=\s*"?([^";]+)"?/.exec(header);

	return plain ? plain[1].trim() : null;
}
