/**
 * Fork feature: geo feed. See docs/fork/GEO_FEED.md
 *
 * What upstream's PostEditModal calls to save a location chosen in
 * GeoLocationPicker. Kept out of the modal so the upstream file carries
 * only a call, not the logic.
 *
 * `pending` is what the picker emits:
 *   { mode: 'pin', lat, lng }  put the pin here by hand
 *   { mode: 'photo' }          drop a hand placed pin, go back to the photo
 *   { mode: 'none' }           off the map, photo coordinates discarded
 *
 * Resolves with the post's new position, rejects with the axios error.
 */
export function applyGeoLocation(statusId, pending) {
	const url = '/api/geo/v1/status/' + statusId + '/location';

	if (pending.mode === 'pin') {
		return axios
			.put(url, { lat: pending.lat, lng: pending.lng })
			.then((res) => res.data);
	}

	return axios
		.delete(url, { params: { mode: pending.mode } })
		.then((res) => res.data);
}

// The server's 422s say something useful ("That is not a usable
// coordinate"); anything else does not.
export function geoErrorMessage(err) {
	const data = err && err.response && err.response.data;
	const message = data ? data.message || data.error : null;

	return message && err.response.status === 422
		? message
		: 'Could not update this post\'s location, please try again later';
}

/**
 * The value to hand upstream's `location` field for a picked place.
 *
 * Upstream's update service only clears a place when `location` is an
 * object without an id — `null` fails its `isset()` and changes nothing —
 * so "no tag" has to be `{}`. Unless there was no tag to begin with, in
 * which case the original value is returned so the modal does not see a
 * change that is not one. Same for re-picking the place already there.
 */
export function locationFieldFor(place, original) {
	if (!place) {
		return original && original.id ? {} : original;
	}

	if (original && String(original.id) === String(place.id)) {
		return original;
	}

	return { id: place.id, name: place.name, country: place.country };
}
