<template>
	<div v-if="visible" class="geo-suggest border-bottom">
		<div class="px-4 py-2">
			<div v-if="!showAlternatives" class="d-flex align-items-start">
				<i class="far fa-map-marker-alt text-primary mt-1 mr-2"></i>

				<div class="flex-grow-1">
					<p class="mb-0 small">
						<span class="text-lighter">Taken near</span>
						<span class="font-weight-bold">{{ suggestion.name }}</span>
						<span class="text-lighter">, {{ suggestion.country }}</span>
					</p>
					<p class="mb-0 text-lighter" style="font-size: 11px">
						From the photo's location data &middot; {{ distanceLabel }}
					</p>

					<div class="mt-2">
						<button class="btn btn-primary btn-sm geo-suggest__btn" @click.prevent="accept()">
							Use this
						</button>
						<button
							v-if="alternatives.length"
							class="btn btn-outline-secondary btn-sm geo-suggest__btn ml-1"
							@click.prevent="showAlternatives = true">
							Somewhere else
						</button>
						<button
							class="btn btn-link btn-sm geo-suggest__btn text-lighter ml-1"
							@click.prevent="optOut">
							Don't add
						</button>
					</div>

					<div v-if="allowExact" class="custom-control custom-switch mt-2">
						<input
							id="geoExactPrecision"
							v-model="exact"
							type="checkbox"
							class="custom-control-input"
							@change="savePrecision">
						<label class="custom-control-label text-lighter" for="geoExactPrecision" style="font-size: 11px">
							Pin the exact spot instead of the city centre
						</label>
					</div>
				</div>
			</div>

			<div v-else>
				<p class="mb-1 small font-weight-bold">Nearby places</p>
				<button
					v-for="place in alternatives"
					:key="place.id"
					class="btn btn-outline-secondary btn-sm btn-block text-left geo-suggest__alt"
					@click.prevent="accept(place)">
					{{ place.name }}, {{ place.country }}
					<span class="float-right text-lighter">{{ formatDistance(place.distance_km) }}</span>
				</button>
				<button class="btn btn-link btn-sm text-lighter px-0" @click.prevent="showAlternatives = false">
					Back
				</button>
			</div>
		</div>
	</div>
</template>

<script type="text/javascript">
	/**
	 * Fork feature: geo feed. See docs/fork/GEO_FEED.md
	 *
	 * Offers the location read out of a photo's GPS, and lets the author
	 * correct it or decline it.
	 *
	 * Purely advisory. If the author ignores this, the server applies the
	 * same suggestion when the post is published — which is how the mobile
	 * apps get location without any client changes. Declining is therefore a
	 * real request that has to reach the server, not just a hidden card:
	 * `optOut` records it on the photo, and `applyGeoChoices` delivers it
	 * once there is an upload to deliver it to, or stops the post.
	 *
	 * Works from the composer's `files`, not its `media`: nothing is
	 * uploaded until publish, so the coordinates are read in the browser
	 * (js/geo/compose.js). The author's answers live on the File too, so they
	 * survive this card being unmounted when the author visits another page
	 * of the composer and comes back.
	 */
	import { firstLocatedPhoto, geoDeclined } from './../../js/geo/compose.js';

	export default {
		props: {
			// The composer's `files`: [{ file: File, editState }]
			files: {
				type: Array,
				default: () => [],
			},

			place: {
				type: [Object, Boolean],
				default: false,
			},
		},

		data() {
			return {
				suggestion: undefined,
				alternatives: [],
				// The File the coordinates came from; its `geo` holds the answer.
				source: null,
				allowExact: false,
				exact: false,
				showAlternatives: false,
				dismissed: false,
				lookup: 0,
			};
		},

		computed: {
			visible() {
				// Nothing to suggest once the author has chosen a location.
				return !!this.suggestion && !this.dismissed && !this.place;
			},

			distanceLabel() {
				return this.formatDistance(this.suggestion.distance_km);
			},
		},

		watch: {
			// Runs whenever a photo is added, removed or reordered: the
			// array is reactive even though the Files in it are not.
			files: {
				immediate: true,
				handler() {
					this.locate();
				},
			},
		},

		methods: {
			async locate() {
				const lookup = ++this.lookup;
				const found = await firstLocatedPhoto(this.files);

				// The photos changed while that was reading; a newer lookup
				// owns the card now.
				if (lookup !== this.lookup) {
					return;
				}

				if (!found || geoDeclined(this.files)) {
					this.reset();
					this.dismissed = !!found;

					return;
				}

				// Same photo still leads: keep the card as the author left it.
				if (found.file === this.source && this.suggestion) {
					return;
				}

				this.reset();
				this.source = found.file;

				axios
					.get('/api/geo/v1/compose/suggest', { params: { lat: found.lat, lng: found.lng } })
					.then((res) => {
						if (lookup !== this.lookup || !res.data.available || !res.data.place) {
							return;
						}

						const chosen = found.file.geo && found.file.geo.precision;

						this.suggestion = res.data.place;
						this.alternatives = res.data.alternatives || [];
						this.allowExact = !!res.data.allow_exact;
						this.exact = (chosen || res.data.precision) === 'exact';
					})
					.catch(() => {
						// A missing suggestion is not worth interrupting the
						// composer over. The author can still search manually.
					});
			},

			/**
			 * @param {Object=} place One of the alternatives, or nothing to
			 *                        take the primary suggestion.
			 */
			accept(place) {
				this.$emit('select', place || this.suggestion);
			},

			optOut() {
				this.dismissed = true;

				if (this.source && this.source.geo) {
					this.source.geo.precision = 'none';
				}
			},

			savePrecision() {
				if (this.source && this.source.geo) {
					this.source.geo.precision = this.exact ? 'exact' : 'city';
				}
			},

			formatDistance(km) {
				if (km == null) {
					return '';
				}

				if (km < 1) {
					return Math.round(km * 1000) + 'm away';
				}

				return Math.round(km) + 'km away';
			},

			reset() {
				this.suggestion = undefined;
				this.alternatives = [];
				this.source = null;
				this.showAlternatives = false;
				this.dismissed = false;
			},
		},
	};
</script>

<style scoped>
	/*
	 * Kept in the component rather than in sass/geo.scss: this renders inside
	 * upstream's compose modal, which loads app.css or spa.css, never the
	 * map page's stylesheet.
	 */
	.geo-suggest__btn {
		font-size: 11px;
		padding: 2px 10px;
		text-transform: uppercase;
		font-weight: 700;
	}

	.geo-suggest__alt {
		font-size: 12px;
		margin-bottom: 4px;
	}
</style>
