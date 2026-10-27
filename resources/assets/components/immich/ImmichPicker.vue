<template>
	<div v-if="available" class="immich-picker" :class="{ 'd-inline-block': compact }">
		<!-- Page 1: a card alongside upstream's "New Post" -->
		<div
			v-if="!compact"
			class="card my-md-3 shadow-none border compose-action text-decoration-none text-dark"
			@click.prevent="open">
			<div class="card-body py-2">
				<div class="media">
					<div class="mr-3 align-items-center justify-content-center immich-picker__icon">
						<i class="far fa-images text-primary fa-lg"></i>
					</div>
					<div class="media-body text-left">
						<p class="mb-0">
							<span class="h5 mt-0 font-weight-bold text-primary">From Immich</span>
						</p>
						<p class="mb-0 text-muted">
							{{ connected ? 'Choose photos from your Immich library' : 'Connect your Immich library in Settings' }}
						</p>
					</div>
				</div>
			</div>
		</div>

		<!-- Page 2: next to upstream's "+" -->
		<button
			v-else-if="connected"
			type="button"
			class="btn btn-outline-primary btn-sm font-weight-bold ml-1"
			title="Add from Immich"
			@click.prevent="open">
			<i class="far fa-images"></i>
		</button>

		<!--
			Not a b-modal: on classic pages the composer is itself inside a
			Bootstrap modal whose focus trap would take focus back from a modal
			mounted elsewhere in the page. This overlay is the composer's own
			descendant, so the trap leaves it alone.
		-->
		<div
			v-if="isOpen"
			class="immich-picker__backdrop"
			@click.self="close"
			@keydown.esc.stop.prevent="close">
			<div class="immich-picker__dialog card" role="dialog" aria-modal="true" aria-label="Choose from Immich">
				<div class="card-header bg-white d-flex align-items-center">
					<span class="font-weight-bold">Choose from Immich</span>
					<button type="button" class="btn btn-link text-muted ml-auto p-0" aria-label="Close" @click.prevent="close">
						<i class="far fa-times fa-lg"></i>
					</button>
				</div>

				<div class="immich-picker__toolbar border-bottom">
					<form class="immich-picker__search" @submit.prevent="runSearch">
						<div class="input-group input-group-sm">
							<input
								ref="search"
								v-model="queryInput"
								type="search"
								class="form-control"
								placeholder="Search, e.g. beach at sunset"
								aria-label="Search your Immich library"
								:disabled="adding">
							<div class="input-group-append">
								<button class="btn btn-outline-secondary" type="submit" :disabled="adding" aria-label="Search">
									<i class="far fa-search"></i>
								</button>
							</div>
						</div>
					</form>

					<select
						v-if="albums.length"
						v-model="album"
						class="custom-select custom-select-sm immich-picker__album"
						:disabled="adding || !!query"
						:title="query ? 'Search covers the whole library' : ''"
						aria-label="Album"
						@change="reload">
						<option :value="null">All photos</option>
						<option v-for="a in albums" :key="a.id" :value="a.id">{{ a.name }} ({{ a.count }})</option>
					</select>
				</div>

				<div ref="body" class="card-body p-1 immich-picker__body">
					<div v-if="error" class="p-4 text-center">
						<p class="text-danger mb-2">{{ error }}</p>
						<a v-if="errorIsSetup" :href="settingsUrl" class="btn btn-primary btn-sm font-weight-bold">Open Immich settings</a>
						<button v-else class="btn btn-outline-secondary btn-sm" @click.prevent="reload">Try again</button>
					</div>

					<template v-else>
						<p v-if="query" class="small text-muted px-2 pt-2 mb-1">
							Results for <strong>{{ query }}</strong>
							<a href="#" class="ml-2" @click.prevent="clearSearch">Clear</a>
						</p>

						<div class="immich-picker__grid">
							<button
								v-for="item in items"
								:key="item.id"
								type="button"
								class="immich-picker__tile"
								:class="{ 'is-selected': isSelected(item) }"
								:aria-pressed="isSelected(item) ? 'true' : 'false'"
								:title="item.filename"
								:disabled="adding"
								@click.prevent="toggle(item)">
								<img :src="item.thumbnail" loading="lazy" alt="" draggable="false">
								<span v-if="item.type === 'video'" class="immich-picker__badge immich-picker__badge--video">
									<i class="fas fa-play"></i>
									<template v-if="item.duration">{{ formatDuration(item.duration) }}</template>
								</span>
								<span v-if="isSelected(item)" class="immich-picker__check">{{ selectionNumber(item) }}</span>
							</button>
						</div>

						<div v-if="loadingPage" class="py-4 text-center">
							<b-spinner small />
						</div>
						<p v-else-if="!items.length" class="text-center text-muted py-5 mb-0">
							{{ query ? 'Nothing matched. Smart search needs machine learning enabled in Immich.' : 'No photos here.' }}
						</p>
						<div ref="sentinel" class="immich-picker__sentinel"></div>
						<div v-if="next && !loadingPage" class="text-center pb-2">
							<button class="btn btn-outline-secondary btn-sm" @click.prevent="loadMore">Load more</button>
						</div>
					</template>
				</div>

				<div class="card-footer bg-white d-flex align-items-center">
					<span v-if="adding" class="small text-muted">
						<b-spinner small class="mr-1" />
						Fetching {{ progress.done + 1 }} of {{ progress.total }}…
					</span>
					<span v-else class="small text-muted">
						{{ selected.length }} of {{ remaining }} selected
						<span v-if="limitHit" class="text-danger ml-1">— that's the most this post can take</span>
					</span>
					<button
						type="button"
						class="btn btn-primary btn-sm font-weight-bold ml-auto"
						:disabled="!selected.length || adding"
						@click.prevent="add">
						Add{{ selected.length ? ' ' + selected.length : '' }}
					</button>
				</div>
			</div>
		</div>
	</div>
</template>

<script type="text/javascript">
	/**
	 * Fork feature: Immich library. See docs/fork/IMMICH.md
	 *
	 * Browse the account's Immich library and hand the chosen photos to the
	 * composer as Files — the composer cannot tell them from photos picked
	 * off the disk, which is the point: everything after this is upstream's.
	 *
	 * Emits `add` with an array of File objects, each carrying `immich`
	 * (asset id, people) for ImmichPeopleSuggest and attachImmichUploads.
	 */
	import { fetchImmichFile, errorMessage } from './../../js/immich/compose.js';

	export default {
		props: {
			// The button by upstream's "+" on page 2, rather than the card.
			compact: {
				type: Boolean,
				default: false,
			},

			// How many more files the post can take.
			remaining: {
				type: Number,
				default: 4,
			},
		},

		data() {
			return {
				available: false,
				connected: false,
				settingsUrl: '/settings/immich',
				isOpen: false,

				items: [],
				next: null,
				loadingPage: false,
				error: null,
				errorIsSetup: false,

				queryInput: '',
				query: '',
				albums: [],
				album: null,
				albumsLoaded: false,

				selected: [],
				limitHit: false,
				adding: false,
				progress: { done: 0, total: 0 },

				observer: null,
				requestSeq: 0,
			};
		},

		mounted() {
			if (!window.App || !App.config.features || App.config.features.immich !== true) {
				return;
			}

			axios
				.get('/api/immich/v1/status')
				.then((res) => {
					this.available = !!res.data.enabled;
					this.connected = !!res.data.connected;
					this.settingsUrl = res.data.settings_url || this.settingsUrl;
				})
				.catch(() => {
					this.available = false;
				});
		},

		beforeDestroy() {
			this.disconnectObserver();
			document.body.classList.remove('immich-picker-open');
		},

		methods: {
			open() {
				if (!this.connected) {
					// Nothing to lose on page 1; on page 2 there are photos in
					// the composer already, so leave it open.
					if (this.compact) {
						window.open(this.settingsUrl, '_blank', 'noopener');
					} else {
						window.location.href = this.settingsUrl;
					}

					return;
				}

				this.isOpen = true;
				this.selected = [];
				this.limitHit = false;
				document.body.classList.add('immich-picker-open');

				if (!this.items.length) {
					this.reload();
				}

				if (!this.albumsLoaded) {
					this.loadAlbums();
				}

				this.$nextTick(() => {
					this.observe();

					if (this.$refs.search && window.matchMedia('(min-width: 768px)').matches) {
						this.$refs.search.focus();
					}
				});
			},

			close() {
				if (this.adding) {
					return;
				}

				this.isOpen = false;
				this.disconnectObserver();
				document.body.classList.remove('immich-picker-open');
			},

			loadAlbums() {
				this.albumsLoaded = true;

				axios
					.get('/api/immich/v1/albums')
					.then((res) => {
						this.albums = res.data.albums || [];
					})
					.catch(() => {
						this.albums = [];
					});
			},

			reload() {
				this.items = [];
				this.next = null;
				this.error = null;

				if (this.$refs.body) {
					this.$refs.body.scrollTop = 0;
				}

				this.fetchPage(null);
			},

			loadMore() {
				if (this.next && !this.loadingPage) {
					this.fetchPage(this.next);
				}
			},

			fetchPage(token) {
				const seq = ++this.requestSeq;
				this.loadingPage = true;

				const params = {};
				if (this.query) {
					params.q = this.query;
				} else if (this.album) {
					params.album = this.album;
				}
				if (token) {
					params.next = token;
				}

				axios
					.get('/api/immich/v1/assets', { params })
					.then((res) => {
						// A newer search or album change has replaced this one.
						if (seq !== this.requestSeq) {
							return;
						}

						const seen = new Set(this.items.map((i) => i.id));
						this.items = this.items.concat((res.data.items || []).filter((i) => !seen.has(i.id)));
						this.next = res.data.next || null;
					})
					.catch(async (err) => {
						if (seq !== this.requestSeq) {
							return;
						}

						const data = err.response && err.response.data;
						this.errorIsSetup = !!data && ['not_connected', 'unauthorized', 'forbidden'].includes(data.error);
						this.error = await errorMessage(err);
					})
					.finally(() => {
						if (seq === this.requestSeq) {
							this.loadingPage = false;
							// A short first page may not fill the dialog, so
							// the sentinel is already visible and will not
							// fire again on its own.
							this.$nextTick(() => this.fillIfShort());
						}
					});
			},

			runSearch() {
				this.query = this.queryInput.trim();
				this.reload();
			},

			clearSearch() {
				this.queryInput = '';
				this.query = '';
				this.reload();
			},

			isSelected(item) {
				return this.selected.some((s) => s.id === item.id);
			},

			selectionNumber(item) {
				return this.selected.findIndex((s) => s.id === item.id) + 1;
			},

			toggle(item) {
				const index = this.selected.findIndex((s) => s.id === item.id);

				if (index !== -1) {
					this.selected.splice(index, 1);
					this.limitHit = false;

					return;
				}

				if (this.selected.length >= this.remaining) {
					this.limitHit = true;

					return;
				}

				this.selected.push(item);
			},

			async add() {
				const chosen = this.selected.slice();
				const files = [];
				const failures = [];

				this.adding = true;
				this.progress = { done: 0, total: chosen.length };

				// One at a time: these are full-size originals, and the
				// composer wants them in the order they were picked.
				for (let i = 0; i < chosen.length; i++) {
					this.progress.done = i;

					try {
						files.push(await fetchImmichFile(chosen[i].id));
					} catch (err) {
						failures.push((chosen[i].filename || 'A photo') + ': ' + (err.message || 'could not be fetched'));
					}
				}

				this.adding = false;
				this.close();

				if (files.length) {
					this.$emit('add', files);
				}

				if (failures.length) {
					swal(
						files.length ? 'Some photos could not be added' : 'Could not add from Immich',
						failures.join('\n'),
						'warning'
					);
				}
			},

			observe() {
				this.disconnectObserver();

				if (!('IntersectionObserver' in window) || !this.$refs.sentinel) {
					return;
				}

				this.observer = new IntersectionObserver(
					(entries) => {
						if (entries.some((e) => e.isIntersecting)) {
							this.loadMore();
						}
					},
					{ root: this.$refs.body, rootMargin: '400px' }
				);

				this.observer.observe(this.$refs.sentinel);
			},

			disconnectObserver() {
				if (this.observer) {
					this.observer.disconnect();
					this.observer = null;
				}
			},

			fillIfShort() {
				const body = this.$refs.body;

				if (body && this.next && body.scrollHeight <= body.clientHeight + 400) {
					this.loadMore();
				}
			},

			formatDuration(seconds) {
				const m = Math.floor(seconds / 60);
				const s = String(seconds % 60).padStart(2, '0');

				return m + ':' + s;
			},
		},
	};
</script>

<style scoped>
	/*
	 * In the component rather than a stylesheet: it renders inside upstream's
	 * composer, which loads app.css or spa.css, never a fork stylesheet.
	 */
	.immich-picker__icon {
		display: inline-flex;
		width: 40px;
		height: 40px;
		border-radius: 100%;
		border: 1px solid #008DF5;
	}

	.immich-picker__backdrop {
		position: fixed;
		inset: 0;
		z-index: 1070;
		background: rgba(0, 0, 0, 0.5);
		display: flex;
		align-items: center;
		justify-content: center;
		padding: 16px;
	}

	.immich-picker__dialog {
		width: 100%;
		max-width: 920px;
		height: min(86vh, 820px);
		display: flex;
		flex-direction: column;
		overflow: hidden;
		border-radius: 12px;
		text-align: left;
	}

	.immich-picker__toolbar {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
		padding: 8px 12px;
	}

	.immich-picker__search {
		flex: 1 1 260px;
	}

	.immich-picker__album {
		flex: 0 1 240px;
		width: auto;
	}

	.immich-picker__body {
		flex: 1 1 auto;
		overflow-y: auto;
		overscroll-behavior: contain;
	}

	.immich-picker__grid {
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
		gap: 4px;
	}

	.immich-picker__tile {
		position: relative;
		display: block;
		padding: 0;
		border: 0;
		background: #e9ecef;
		aspect-ratio: 1 / 1;
		overflow: hidden;
		cursor: pointer;
		border-radius: 2px;
	}

	.immich-picker__tile img {
		width: 100%;
		height: 100%;
		object-fit: cover;
		display: block;
		transition: transform 0.12s ease;
	}

	.immich-picker__tile.is-selected img {
		transform: scale(0.88);
		border-radius: 6px;
	}

	.immich-picker__tile.is-selected {
		background: #cfe6fb;
	}

	.immich-picker__tile:focus-visible {
		outline: 3px solid #008DF5;
		outline-offset: -3px;
	}

	.immich-picker__check {
		position: absolute;
		top: 6px;
		right: 6px;
		min-width: 24px;
		height: 24px;
		padding: 0 6px;
		border-radius: 12px;
		background: #008DF5;
		color: #fff;
		font-size: 12px;
		font-weight: 700;
		line-height: 24px;
		text-align: center;
		box-shadow: 0 0 0 2px #fff;
	}

	.immich-picker__badge {
		position: absolute;
		left: 6px;
		bottom: 6px;
		padding: 1px 6px;
		border-radius: 4px;
		background: rgba(0, 0, 0, 0.6);
		color: #fff;
		font-size: 11px;
		font-weight: 600;
	}

	.immich-picker__sentinel {
		height: 1px;
	}

	@media (max-width: 575px) {
		.immich-picker__backdrop {
			padding: 0;
		}

		.immich-picker__dialog {
			height: 100%;
			max-width: none;
			border-radius: 0;
		}

		.immich-picker__grid {
			grid-template-columns: repeat(3, 1fr);
		}

		.immich-picker__album {
			flex-basis: 100%;
		}
	}
</style>

<style>
	/* Stop the page behind the picker scrolling with it. */
	body.immich-picker-open {
		overflow: hidden;
	}
</style>
