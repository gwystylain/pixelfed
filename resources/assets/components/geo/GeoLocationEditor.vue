<template>
	<div class="geo-editor" role="dialog" aria-label="Edit this post's location">
		<div class="geo-editor__head">
			<span class="font-weight-bold">
				<i class="far fa-map-marker-alt mr-1"></i> Edit location
			</span>

			<button
				type="button"
				class="btn btn-link geo-editor__close"
				title="Cancel"
				aria-label="Cancel"
				@click="$emit('cancel')">
				<i class="far fa-times"></i>
			</button>
		</div>

		<p class="geo-editor__hint">
			Drag the pin on the map, or search for where it should be.
		</p>

		<form class="geo-editor__search" @submit.prevent="search">
			<input
				ref="query"
				v-model="query"
				type="search"
				class="form-control form-control-sm"
				:placeholder="placeholder"
				aria-label="Address or coordinates">

			<button
				type="submit"
				class="btn btn-sm btn-outline-secondary"
				:disabled="searching || query.trim().length < 2">
				<i class="far" :class="searching ? 'fa-circle-notch fa-spin' : 'fa-search'"></i>
			</button>
		</form>

		<p v-if="searchError" class="geo-editor__note text-danger">{{ searchError }}</p>
		<p v-else-if="searched && !results.length" class="geo-editor__note text-muted">
			Nothing found. Try a nearby town, or paste coordinates.
		</p>

		<ul v-if="results.length" class="geo-editor__results">
			<li v-for="(result, index) in results" :key="index">
				<button type="button" class="geo-editor__result" @click="choose(result)">
					{{ result.label }}
				</button>
			</li>
		</ul>

		<p class="geo-editor__coords">
			<span class="text-muted">Pin</span>
			<span class="font-weight-bold">{{ formatted }}</span>
			<span v-if="moved" class="geo-editor__moved">moved</span>
		</p>

		<p v-if="saveError" class="geo-editor__note text-danger">{{ saveError }}</p>

		<div class="geo-editor__actions">
			<button
				v-if="canReset"
				type="button"
				class="btn btn-sm btn-link text-muted px-0"
				:disabled="saving"
				title="Put the pin back where the photo says"
				@click="reset">
				Reset to photo
			</button>

			<span class="geo-editor__spacer"></span>

			<button type="button" class="btn btn-sm btn-outline-secondary" :disabled="saving" @click="$emit('cancel')">
				Cancel
			</button>

			<button
				type="button"
				class="btn btn-sm btn-primary font-weight-bold"
				:disabled="saving || !moved"
				@click="apply">
				<i v-if="saving" class="far fa-circle-notch fa-spin mr-1"></i>Apply
			</button>
		</div>
	</div>
</template>

<script type="text/javascript">
	/**
	 * Fork feature: geo feed. See docs/fork/GEO_FEED.md
	 *
	 * Moving one post's pin, for when the camera's fix was wrong.
	 *
	 * The marker itself belongs to GeoFeed, which owns the map: this emits
	 * `move` when a search result is picked and takes `lat`/`lng` back as
	 * props when the marker is dragged, so there is one source of truth for
	 * where the pin currently is.
	 */

	export default {
		props: {
			postId: {
				type: String,
				required: true,
			},

			lat: {
				type: Number,
				required: true,
			},

			lng: {
				type: Number,
				required: true,
			},

			// Where it started, so "Apply" can tell whether anything changed.
			originLat: {
				type: Number,
				required: true,
			},

			originLng: {
				type: Number,
				required: true,
			},

			// Only a pin that was placed by hand has something to reset to.
			canReset: {
				type: Boolean,
				default: false,
			},
		},

		data() {
			return {
				query: '',
				results: [],
				searching: false,
				searched: false,
				searchError: undefined,
				saving: false,
				saveError: undefined,
			};
		},

		computed: {
			formatted() {
				return this.lat.toFixed(6) + ', ' + this.lng.toFixed(6);
			},

			// Six decimal places is ~11cm; below that it is the same place.
			moved() {
				return (
					Math.abs(this.lat - this.originLat) > 0.0000005 ||
					Math.abs(this.lng - this.originLng) > 0.0000005
				);
			},

			placeholder() {
				return 'Address, or 51.507400, -0.127800';
			},
		},

		mounted() {
			this.$nextTick(() => {
				if (this.$refs.query) {
					this.$refs.query.focus();
				}
			});
		},

		methods: {
			search() {
				const q = this.query.trim();

				if (q.length < 2 || this.searching) {
					return;
				}

				this.searching = true;
				this.searchError = undefined;

				axios
					.get('/api/geo/v1/geocode', { params: { q: q } })
					.then((res) => {
						this.results = res.data.results || [];
						this.searched = true;
					})
					.catch(() => {
						this.searchError = 'Could not look that up just now';
						this.results = [];
					})
					.finally(() => {
						this.searching = false;
					});
			},

			choose(result) {
				this.results = [];
				this.searched = false;
				this.$emit('move', result.lat, result.lng);
			},

			apply() {
				if (this.saving) {
					return;
				}

				this.saving = true;
				this.saveError = undefined;

				axios
					.put('/api/geo/v1/status/' + this.postId + '/location', {
						lat: this.lat,
						lng: this.lng,
					})
					.then((res) => {
						this.$emit('saved', res.data);
					})
					.catch((err) => {
						this.saveError = this.messageFor(err, 'Could not move the pin');
					})
					.finally(() => {
						this.saving = false;
					});
			},

			reset() {
				if (this.saving) {
					return;
				}

				this.saving = true;
				this.saveError = undefined;

				axios
					.delete('/api/geo/v1/status/' + this.postId + '/location')
					.then((res) => {
						this.$emit('saved', res.data);
					})
					.catch((err) => {
						this.saveError = this.messageFor(err, 'Could not reset the pin');
					})
					.finally(() => {
						this.saving = false;
					});
			},

			// The server's 422s say something useful ("That is not a usable
			// coordinate"); anything else does not.
			messageFor(err, fallback) {
				const message = err && err.response && err.response.data
					? err.response.data.message || err.response.data.error
					: null;

				return message && err.response.status === 422 ? message : fallback;
			},
		},
	};
</script>
