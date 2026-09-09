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

					<p v-if="error" class="mb-0 mt-1 text-danger" style="font-size: 11px">
						{{ error }}
					</p>

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
	 * `optOut` tells it to leave this post off the map and discards the
	 * stored coordinates.
	 */
	export default {
		props: {
			media: {
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
				mediaId: undefined,
				allowExact: false,
				exact: false,
				showAlternatives: false,
				dismissed: false,
				error: undefined,
				attempts: 0,
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
			media: {
				immediate: true,
				handler(media) {
					if (!media || !media.length) {
						this.reset();

						return;
					}

					this.attempts = 0;
					this.load();
				},
			},
		},

		methods: {
			load() {
				const ids = this.media
					.map((m) => m.id)
					.filter((id) => id)
					.join(',');

				if (!ids.length) {
					return;
				}

				axios
					.get('/api/geo/v1/compose/suggest', { params: { ids } })
					.then((res) => {
						if (res.data.available && res.data.place) {
							this.suggestion = res.data.place;
							this.alternatives = res.data.alternatives || [];
							this.mediaId = res.data.media_id;
							this.allowExact = !!res.data.allow_exact;
							this.exact = res.data.precision === 'exact';

							return;
						}

						// With queued extraction the coordinates may not be
						// written yet. Two retries covers it; beyond that the
						// photo simply has no GPS.
						if (this.attempts < 2) {
							this.attempts++;
							setTimeout(this.load, 2500);
						}
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
				this.error = undefined;
				this.dismissed = true;

				if (!this.mediaId) {
					return;
				}

				axios
					.put('/api/geo/v1/compose/media/' + this.mediaId, { precision: 'none' })
					.catch(() => {
						// This one has to be surfaced rather than swallowed:
						// the author asked not to be located, and if the
						// server did not hear it the post still gets a
						// location assigned on publish.
						this.dismissed = false;
						this.error = 'Could not turn the location off. Try again, or remove it after posting.';
					});
			},

			savePrecision() {
				if (!this.mediaId) {
					return;
				}

				axios
					.put('/api/geo/v1/compose/media/' + this.mediaId, {
						precision: this.exact ? 'exact' : 'city',
					})
					.catch(() => {
						this.exact = !this.exact;
					});
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
				this.mediaId = undefined;
				this.showAlternatives = false;
				this.dismissed = false;
				this.error = undefined;
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
