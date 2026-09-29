<template>
	<div class="geo-picker">
		<div v-if="loading" class="geo-picker__loading">
			<b-spinner small variant="primary" />
		</div>

		<template v-else>
			<form class="geo-picker__search" @submit.prevent="search">
				<input
					ref="query"
					v-model="query"
					type="search"
					class="form-control form-control-sm"
					placeholder="Address, place, or 51.507400, -0.127800"
					aria-label="Address, place or coordinates">

				<button
					type="submit"
					class="btn btn-sm btn-outline-secondary"
					title="Search"
					aria-label="Search"
					:disabled="searching || query.trim().length < 2">
					<i class="far" :class="searching ? 'fa-circle-notch fa-spin' : 'fa-search'"></i>
				</button>
			</form>

			<p v-if="searchError" class="geo-picker__note text-danger">{{ searchError }}</p>
			<p v-else-if="searched && !results.length" class="geo-picker__note text-muted">
				Nothing found. Try a nearby town, or paste coordinates.
			</p>

			<ul v-if="results.length" class="geo-picker__results">
				<li v-for="(result, index) in results" :key="index">
					<button type="button" class="geo-picker__result" @click="choose(result)">
						{{ result.label }}
					</button>
				</li>
			</ul>

			<template v-if="mappable">
				<div ref="map" class="geo-picker__map"></div>

				<p class="geo-picker__note text-muted">
					<template v-if="pin">
						Click the map or drag the pin to move it.
						<span class="geo-picker__coords">{{ formatted }}</span>
					</template>
					<template v-else>
						Click the map to drop a pin, or search above.
					</template>
				</p>
			</template>

			<label class="geo-picker__label" for="geo-picker-tag">Location tag</label>
			<select
				id="geo-picker-tag"
				v-model="placeId"
				class="custom-select custom-select-sm"
				:disabled="nearbyLoading"
				@change="emitChange">
				<option value="">No location tag</option>
				<option v-for="place in placeOptions" :key="place.id" :value="String(place.id)">
					{{ place.name }}, {{ place.country }}<template v-if="place.distance_km != null"> ({{ distanceLabel(place) }})</template>
				</option>
			</select>

			<p v-if="pending" class="geo-picker__pending">
				<i class="far fa-info-circle mr-1"></i>{{ pendingLabel }}
				<a href="#" class="ml-1" @click.prevent="undo">Undo</a>
			</p>

			<div class="geo-picker__actions">
				<button
					v-if="canReset"
					type="button"
					class="btn btn-sm btn-link text-muted px-0"
					title="Put the pin back where the photo says"
					@click="resetToPhoto">
					Reset to photo
				</button>

				<span class="geo-picker__spacer"></span>

				<button
					v-if="canRemove"
					type="button"
					class="btn btn-sm btn-link text-danger px-0"
					title="No tag, off the map, and the photo's coordinates discarded"
					@click="remove">
					<i class="far fa-trash-alt mr-1"></i>Remove location
				</button>
			</div>
		</template>
	</div>
</template>

<script type="text/javascript">
	/**
	 * Fork feature: geo feed. See docs/fork/GEO_FEED.md
	 *
	 * The Location tab of upstream's Edit Post modal, when the geo feed is
	 * on. The author can type an address, click the map to drop a pin, or
	 * drag the pin, and the post's location tag follows to the nearest city.
	 *
	 * Nothing is saved here. It emits `change` with the pin to save and the
	 * place to tag, and the modal saves both when the author presses Save,
	 * so Cancel means cancel.
	 */

	let L;

	export default {
		props: {
			statusId: {
				type: String,
				required: true,
			},

			// The post's location tag when the modal opened.
			place: {
				type: Object,
				default: null,
			},

			// What is already chosen but not saved. The modal's tabs are
			// v-if'd, so this component is rebuilt each time the Location tab
			// is opened and has to pick up where the author left it.
			selected: {
				type: Object,
				default: null,
			},

			unsaved: {
				type: Object,
				default: null,
			},
		},

		data() {
			return {
				loading: true,
				info: null,

				// Where the pin is drawn; `pending` is what Save will write.
				pin: null,
				pending: this.unsaved,

				placeId: this.selected && this.selected.id ? String(this.selected.id) : '',
				nearby: [],
				nearbyLoading: false,
				nearbyRequest: 0,

				query: '',
				results: [],
				searching: false,
				searched: false,
				searchError: undefined,

				map: undefined,
				marker: undefined,
				gone: false,
			};
		},

		computed: {
			mappable() {
				return !!(this.info && this.info.mappable);
			},

			formatted() {
				return this.pin
					? this.pin.lat.toFixed(6) + ', ' + this.pin.lng.toFixed(6)
					: '';
			},

			// The current tag stays selectable even when it is not near the
			// pin, so opening the tab never silently changes it.
			placeOptions() {
				const options = this.nearby.slice();

				[this.selected, this.place].forEach((place) => {
					if (place && place.id && !options.some((p) => String(p.id) === String(place.id))) {
						options.unshift(place);
					}
				});

				return options;
			},

			canReset() {
				return !!(
					this.info &&
					this.info.photo &&
					this.info.source === 'manual' &&
					!(this.pending && this.pending.mode !== 'pin')
				);
			},

			canRemove() {
				if (this.pending && this.pending.mode === 'none') {
					return false;
				}

				return !!(this.pin || this.placeId || (this.info && this.info.photo));
			},

			pendingLabel() {
				switch (this.pending && this.pending.mode) {
					case 'pin':
						return 'The pin will be moved when you save.';
					case 'photo':
						return 'The pin will go back to where the photo says when you save.';
					case 'none':
						return 'This post will be taken off the map when you save.';
					default:
						return '';
				}
			},
		},

		async mounted() {
			try {
				const res = await axios.get('/api/geo/v1/status/' + this.statusId + '/location');
				this.info = res.data;
			} catch (e) {
				// Without its position the picker can still tag a place;
				// it just cannot show or move a pin.
				this.info = { mappable: false, photo: null, source: null, autotag_max_km: 50 };
			}

			this.pin = this.pinFor(this.pending);

			if (this.pin) {
				this.loadNearby(this.pin, false);
			}

			this.loading = false;

			if (!this.mappable) {
				return;
			}

			L = (await import(/* webpackChunkName: "leaflet" */ 'leaflet')).default;

			if (this.gone) {
				return;
			}

			this.$nextTick(this.initMap);
		},

		beforeDestroy() {
			this.gone = true;

			if (this.map) {
				this.map.remove();
			}
		},

		methods: {
			initMap() {
				if (!this.$refs.map) {
					return;
				}

				const cfg = this.info.map || {};
				const start = this.pin || this.info.photo || this.placeCoords(this.place);

				this.map = L.map(this.$refs.map, {
					minZoom: cfg.min_zoom || 2,
					maxZoom: cfg.max_zoom || 18,
					worldCopyJump: true,
				}).setView(start ? [start.lat, start.lng] : [20, 0], start ? 14 : 2);

				L.tileLayer(cfg.tile_url || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
					attribution: cfg.tile_attribution,
					maxZoom: cfg.max_zoom || 18,
					detectRetina: true,
				}).addTo(this.map);

				this.map.on('click', (e) => {
					const at = e.latlng.wrap();
					this.movePin(at.lat, at.lng, false);
				});

				if (this.pin) {
					this.drawMarker();
				}

				// The tab has only just been laid out inside the modal, and
				// Leaflet measured it before the modal finished animating.
				setTimeout(() => {
					if (this.map) {
						this.map.invalidateSize();
					}
				}, 250);
			},

			drawMarker() {
				if (!this.map || !this.pin) {
					return;
				}

				if (this.marker) {
					this.marker.setLatLng([this.pin.lat, this.pin.lng]);
					return;
				}

				this.marker = L.marker([this.pin.lat, this.pin.lng], {
					draggable: true,
					autoPan: true,
					keyboard: true,
					title: 'Drag to where this post belongs',
					icon: L.divIcon({
						html: '<div class="geo-picker-pin__inner"><i class="fas fa-map-marker-alt"></i></div>',
						className: 'geo-picker-pin',
						iconSize: [32, 32],
						iconAnchor: [16, 32],
					}),
				}).addTo(this.map);

				this.marker.on('dragend', () => {
					const at = this.marker.getLatLng().wrap();
					this.movePin(at.lat, at.lng, false);
				});
			},

			clearMarker() {
				if (this.marker) {
					this.marker.remove();
					this.marker = undefined;
				}
			},

			movePin(lat, lng, recentre) {
				this.pin = { lat, lng };
				this.pending = { mode: 'pin', lat, lng };
				this.drawMarker();

				if (recentre && this.map) {
					// Close enough to see what was picked, without throwing
					// away a deliberate zoom.
					this.map.setView([lat, lng], Math.max(this.map.getZoom(), 15));
				}

				this.loadNearby(this.pin, true);
				this.emitChange();
			},

			/**
			 * Cities near the pin, for the tag dropdown. With `retag`, the
			 * nearest one within the autotag distance is selected — the
			 * same rule the server uses when it tags a post itself.
			 */
			loadNearby(at, retag) {
				const request = ++this.nearbyRequest;
				this.nearbyLoading = retag;

				return axios
					.get('/api/geo/v1/places/nearby', { params: { lat: at.lat, lng: at.lng, limit: 5 } })
					.then((res) => {
						if (request !== this.nearbyRequest) {
							return;
						}

						this.nearby = res.data.places || [];

						if (retag) {
							const nearest = this.nearby[0];
							const maxKm = this.info.autotag_max_km || 50;

							this.placeId = nearest && nearest.distance_km <= maxKm
								? String(nearest.id)
								: '';

							this.emitChange();
						}
					})
					.catch(() => {})
					.finally(() => {
						if (request === this.nearbyRequest) {
							this.nearbyLoading = false;
						}
					});
			},

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
				this.query = result.label || '';

				if (this.mappable) {
					this.movePin(result.lat, result.lng, true);
					return;
				}

				// No pin to move on a post that cannot be on the map, but
				// the address still says which city to tag.
				this.loadNearby({ lat: result.lat, lng: result.lng }, true);
			},

			resetToPhoto() {
				this.pending = { mode: 'photo' };
				this.pin = { lat: this.info.photo.lat, lng: this.info.photo.lng };
				this.drawMarker();

				if (this.map) {
					this.map.panTo([this.pin.lat, this.pin.lng]);
				}

				this.emitChange();
			},

			remove() {
				// A nearby lookup still in flight would re-tag the post.
				this.nearbyRequest++;
				this.nearbyLoading = false;
				this.pending = { mode: 'none' };
				this.pin = null;
				this.placeId = '';
				this.clearMarker();
				this.emitChange();
			},

			undo() {
				this.nearbyRequest++;
				this.nearbyLoading = false;
				this.pending = null;
				this.placeId = this.place && this.place.id ? String(this.place.id) : '';
				this.pin = this.pinFor(null);

				if (this.pin) {
					this.drawMarker();

					if (this.map) {
						this.map.panTo([this.pin.lat, this.pin.lng]);
					}
				} else {
					this.clearMarker();
				}

				this.emitChange();
			},

			emitChange() {
				const place = this.placeOptions.find((p) => String(p.id) === this.placeId) || null;

				this.$emit('change', { geo: this.pending, place: place });
			},

			// Where the pin is drawn for a given unsaved change.
			pinFor(pending) {
				const mode = pending && pending.mode;

				if (mode === 'pin') {
					return { lat: pending.lat, lng: pending.lng };
				}

				if (mode === 'none') {
					return null;
				}

				if (mode === 'photo' && this.info.photo) {
					return { lat: this.info.photo.lat, lng: this.info.photo.lng };
				}

				return this.info.lat != null && this.info.lng != null
					? { lat: this.info.lat, lng: this.info.lng }
					: null;
			},

			placeCoords(place) {
				return place && place.lat != null && place.lng != null
					? { lat: Number(place.lat), lng: Number(place.lng) }
					: null;
			},

			distanceLabel(place) {
				const km = Number(place.distance_km);

				return km < 1 ? '< 1 km' : Math.round(km) + ' km';
			},
		},
	};
</script>

<style lang="scss">
	@import '~leaflet/dist/leaflet.css';

	// Not scoped: Leaflet builds the marker outside this component's tree.
	.geo-picker-pin {
		background: none;
		border: 0;

		&__inner {
			display: flex;
			align-items: flex-end;
			justify-content: center;
			width: 32px;
			height: 32px;
			color: var(--primary, #3b82f6);
			font-size: 30px;
			line-height: 1;
			filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.45));
			cursor: grab;
		}
	}
</style>

<style lang="scss" scoped>
	.geo-picker {
		&__loading {
			display: flex;
			justify-content: center;
			padding: 2rem 0;
		}

		&__search {
			display: flex;
			gap: 0.4rem;
		}

		&__results {
			list-style: none;
			margin: 0.4rem 0 0;
			padding: 0;
			border: 1px solid var(--border-color, #dee2e6);
			border-radius: 6px;
			max-height: 160px;
			overflow-y: auto;
		}

		&__result {
			display: block;
			width: 100%;
			padding: 0.4rem 0.6rem;
			border: 0;
			background: none;
			text-align: left;
			font-size: 13px;
			color: inherit;

			&:hover,
			&:focus {
				background: var(--light-gray, rgba(0, 0, 0, 0.05));
			}
		}

		&__map {
			height: 220px;
			margin-top: 0.6rem;
			border-radius: 6px;
			overflow: hidden;

			// Leaflet's own panes sit at z-index 400+; keep them inside the
			// modal rather than above its footer.
			position: relative;
			z-index: 0;
		}

		&__note {
			margin: 0.4rem 0 0;
			font-size: 12px;
		}

		&__coords {
			font-family: var(--font-family-monospace, monospace);
			margin-left: 0.3rem;
		}

		&__label {
			display: block;
			margin: 0.8rem 0 0.3rem;
			font-size: 12px;
			font-weight: 700;
		}

		&__pending {
			margin: 0.6rem 0 0;
			font-size: 12px;
			color: var(--text-lighter, #6c757d);
		}

		&__actions {
			display: flex;
			align-items: center;
			margin-top: 0.4rem;
		}

		&__spacer {
			flex: 1;
		}
	}
</style>
