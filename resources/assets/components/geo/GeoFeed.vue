<template>
	<div
		class="geo-feed"
		:class="{ 'geo-feed--pane-open': hasSelection, 'geo-feed--pane-full': paneExpanded }">
		<div class="geo-feed__bar">
			<div class="geo-feed__bar-title">
				<i class="far fa-map-marked-alt mr-2"></i>
				<span class="font-weight-bold">Photo Map</span>
			</div>

			<div class="geo-feed__bar-status">
				<span v-if="loading" class="text-muted small">
					<i class="fas fa-circle-notch fa-spin mr-1"></i> Loading&hellip;
				</span>
				<span v-else-if="error" class="text-danger small">
					<i class="far fa-exclamation-triangle mr-1"></i> {{ error }}
				</span>
				<span v-else-if="mode === 'clusters'" class="text-muted small">
					{{ totalInView }} {{ totalInView === 1 ? 'post' : 'posts' }} &middot; zoom in to browse
				</span>
				<span v-else-if="pins.length" class="text-muted small">
					{{ totalInView }} {{ totalInView === 1 ? 'post' : 'posts' }} here
				</span>
				<span v-else-if="!isFullRange" class="text-muted small">
					No posts in this date range
				</span>
				<span v-else class="text-muted small">No posts in view</span>
			</div>

			<div
				v-if="hasDateFilter"
				class="geo-dates"
				role="group"
				aria-label="Filter posts by date">
				<div class="geo-dates__slider" @click="onTrackClick">
					<span class="geo-dates__track"></span>
					<span class="geo-dates__fill" :style="fillStyle"></span>

					<input
						type="range"
						class="geo-dates__handle"
						min="0"
						step="1"
						:max="spanDays"
						:value="fromDay"
						aria-label="Earliest date"
						:aria-valuetext="isoDay(fromDate)"
						@input="onHandle('from', $event)">

					<input
						type="range"
						class="geo-dates__handle"
						min="0"
						step="1"
						:max="spanDays"
						:value="toDay"
						aria-label="Latest date"
						:aria-valuetext="isoDay(toDate)"
						@input="onHandle('to', $event)">
				</div>

				<span class="geo-dates__label text-muted small">
					{{ rangeLabel }}
				</span>

				<div
					class="btn-group btn-group-sm geo-dates__presets"
					role="group"
					aria-label="Date presets">
					<button
						v-for="preset in presets"
						:key="preset.key"
						type="button"
						class="btn"
						:class="activePreset === preset.key ? 'btn-primary' : 'btn-outline-secondary'"
						:title="preset.title"
						:aria-label="preset.title"
						:aria-pressed="activePreset === preset.key ? 'true' : 'false'"
						@click="applyPreset(preset.key)">
						{{ preset.label }}
					</button>
				</div>
			</div>

			<div class="geo-feed__bar-actions">
				<button
					class="btn btn-outline-secondary btn-sm"
					:disabled="locating"
					title="Jump to my location"
					@click="locate">
					<i class="far" :class="locating ? 'fa-circle-notch fa-spin' : 'fa-location'"></i>
					<span class="d-none d-md-inline ml-1">Near me</span>
				</button>
			</div>
		</div>

		<div class="geo-feed__body">
			<div ref="map" class="geo-feed__map"></div>

			<aside v-if="hasSelection" class="geo-feed__pane" aria-label="Selected post">
				<geo-post-pane
					:key="selectedId"
					:post-id="selectedId"
					:position="selectedIndex"
					:count="gallery.length"
					:expanded="paneExpanded"
					@close="closePost()"
					@prev="step(-1)"
					@next="step(1)"
					@toggle-expand="paneExpanded = !paneExpanded"
					@gone="dropPost(selectedId)"
					@filtered="dropAccount"
					/>
			</aside>
		</div>
	</div>
</template>

<script type="text/javascript">
	/**
	 * Fork feature: geo feed. See docs/fork/GEO_FEED.md
	 *
	 * Leaflet is driven directly rather than through a wrapper library. The
	 * map holds hundreds of markers that are torn down and rebuilt on every
	 * pan, and keeping that out of Vue's reactivity is both simpler and
	 * considerably faster than letting it diff a marker array.
	 *
	 * Opening a pin splits the page: map on one side, the post on the other,
	 * rendered by upstream's own status component so it behaves exactly as it
	 * does in the feed. The post pane is a separate chunk — a viewer who only
	 * browses the map never loads it.
	 */

	// Leaflet is imported on demand. `mix.extract()` hoists node_modules into
	// vendor.js, so the bytes ship with every page regardless; this only keeps
	// the module from being evaluated on pages that never draw a map.
	let L = null;

	const STORAGE_KEY = 'pf.geo.lastView';
	const MOVE_DEBOUNCE_MS = 350;
	const POST_PARAM = 'post';
	const DAY_MS = 86400000;

	// Slider handle width. Shared with geo.scss, which cannot be read from
	// here — change it in both or the fill drifts off the handles.
	const HANDLE_PX = 14;

	// `start` walks back from today with date arithmetic rather than a day
	// count, so "6 months" means six calendar months and not 180 days. It
	// mutates the date it is handed; callers pass a copy.
	const PRESETS = [
		{
			key: '30d',
			label: '30d',
			title: 'Last 30 days',
			start: (d) => {
				d.setDate(d.getDate() - 30);

				return d;
			},
		},
		{
			key: '6m',
			label: '6m',
			title: 'Last 6 months',
			start: (d) => {
				d.setMonth(d.getMonth() - 6);

				return d;
			},
		},
		{
			key: '1y',
			label: '1y',
			title: 'Last year',
			start: (d) => {
				d.setFullYear(d.getFullYear() - 1);

				return d;
			},
		},
		{ key: 'all', label: 'All', title: 'All dates', start: null },
	];

	function startOfDay(date) {
		const copy = new Date(date);

		copy.setHours(0, 0, 0, 0);

		return copy;
	}

	export default {
		components: {
			'geo-post-pane': () => import(/* webpackChunkName: "geo-post" */ './GeoPostPane.vue'),
		},

		data() {
			const config = window._geoConfig || {};

			// The slider spans from the oldest post on the map to today. An
			// explicit local midnight, because `new Date('2026-03-01')` is
			// parsed as UTC and lands on the day before in half the world.
			const today = startOfDay(new Date());
			const origin = config.oldestDate
				? startOfDay(new Date(config.oldestDate + 'T00:00:00'))
				: startOfDay(new Date(today.getFullYear() - 1, today.getMonth(), today.getDate()));

			// A floor of one keeps the arithmetic honest on an instance whose
			// only pinned posts are from today.
			const span = Math.max(1, Math.round((today - origin) / DAY_MS));

			return {
				config: config,
				loading: false,
				locating: false,
				error: undefined,
				mode: 'clusters',
				clusters: [],
				pins: [],

				// The posts sharing the open pin, and which of them is showing.
				// One pin holds many posts at city precision, so the pane gets
				// prev/next rather than making the viewer reopen the popup.
				gallery: [],
				selectedIndex: 0,

				// Small screens cannot split usefully, so the pane can take
				// the whole page and hand the map back on request.
				paneExpanded: false,

				// Date filter, held as day offsets from `dateOrigin` so the
				// two slider handles are plain integers.
				dateOrigin: origin,
				dateToday: today,
				spanDays: span,
				fromDay: 0,
				toDay: span,
			};
		},

		computed: {
			totalInView() {
				if (this.mode === 'clusters') {
					return this.clusters.reduce((sum, c) => sum + c.count, 0);
				}

				return this.pins.reduce((sum, pin) => sum + pin.posts.length, 0);
			},

			hasSelection() {
				return this.gallery.length > 0;
			},

			selectedId() {
				const post = this.gallery[this.selectedIndex];

				return post ? post.id : undefined;
			},

			presets() {
				return PRESETS;
			},

			// Nothing is pinned, so there is nothing to filter and no honest
			// left-hand end for the slider to have.
			hasDateFilter() {
				return this.config.oldestDate != null;
			},

			fromDate() {
				return this.dayToDate(this.fromDay);
			},

			toDate() {
				return this.dayToDate(this.toDay);
			},

			isFullRange() {
				return this.fromDay <= 0 && this.toDay >= this.spanDays;
			},

			/**
			 * Derived rather than remembered: dragging a handle then clears
			 * the highlight on its own, and a preset that happens to cover
			 * everything highlights as "All", which is what it is.
			 */
			activePreset() {
				if (this.isFullRange) {
					return 'all';
				}

				// Every preset ends today. A range that does not cannot be one.
				if (this.toDay < this.spanDays) {
					return undefined;
				}

				const match = PRESETS.find(
					(preset) => preset.start && this.presetFromDay(preset) === this.fromDay
				);

				return match ? match.key : undefined;
			},

			rangeLabel() {
				if (this.isFullRange) {
					return 'All dates';
				}

				const short = { day: 'numeric', month: 'short' };
				const long = { day: 'numeric', month: 'short', year: 'numeric' };
				const end = this.toDate.toLocaleDateString(undefined, long);

				// Both handles on the same day is one day, not a range of one.
				if (this.fromDay === this.toDay) {
					return end;
				}

				const sameYear = this.fromDate.getFullYear() === this.toDate.getFullYear();

				return (
					this.fromDate.toLocaleDateString(undefined, sameYear ? short : long) +
					' – ' +
					end
				);
			},

			/**
			 * A range input insets its thumb by half its width, so the fill
			 * has to live in the same inset coordinate space or it drifts
			 * away from the handles at the ends of the track.
			 */
			fillStyle() {
				const half = 'calc(' + HANDLE_PX / 2 + 'px + (100% - ' + HANDLE_PX + 'px) * ';

				return {
					left: half + this.fromDay / this.spanDays + ')',
					right: half + (1 - this.toDay / this.spanDays) + ')',
				};
			},
		},

		watch: {
			// The open pin is drawn differently, so a change of selection is a
			// repaint. Cheaper to hang it off the id than to remember to call
			// render() from every path that changes one.
			selectedId() {
				this.render();
			},
		},

		created() {
			// A linked post opens before the map does: the pane fetches the
			// status itself and does not need coordinates to render it.
			this.syncFromUrl({ history: false });
		},

		async mounted() {
			window.addEventListener('popstate', this.onPopState);
			document.addEventListener('keydown', this.onKeydown);

			L = (await import(/* webpackChunkName: "leaflet" */ 'leaflet')).default;

			// Loading Leaflet is a round trip; the viewer may have navigated
			// away in the meantime, taking $refs.map with them.
			if (this.gone) {
				return;
			}

			this.initMap();
		},

		beforeDestroy() {
			this.gone = true;

			window.removeEventListener('popstate', this.onPopState);
			document.removeEventListener('keydown', this.onKeydown);

			if (this.moveTimer) {
				clearTimeout(this.moveTimer);
			}

			if (this.request) {
				this.request.abort();
			}

			if (this.map) {
				this.map.remove();
			}
		},

		methods: {
			initMap() {
				const view = this.restoreView();

				this.map = L.map(this.$refs.map, {
					minZoom: this.config.minZoom || 2,
					maxZoom: this.config.maxZoom || 18,
					worldCopyJump: true,
					zoomControl: true,
					attributionControl: true,
				}).setView([view.lat, view.lng], view.zoom);

				L.tileLayer(this.config.tileUrl, {
					attribution: this.config.tileAttribution,
					maxZoom: this.config.maxZoom || 18,
					detectRetina: true,
				}).addTo(this.map);

				this.markers = L.layerGroup().addTo(this.map);

				this.map.on('moveend zoomend', this.onMove);

				this.fetch();
			},

			onMove() {
				this.persistView();
				this.scheduleFetch();
			},

			// Shared by panning and by dragging a slider handle: both fire
			// continuously, and `fetch` drops whatever is in flight.
			scheduleFetch() {
				if (this.moveTimer) {
					clearTimeout(this.moveTimer);
				}

				this.moveTimer = setTimeout(this.fetch, MOVE_DEBOUNCE_MS);
			},

			fetch() {
				if (!this.map) {
					return;
				}

				// A pan that lands mid-request makes the in-flight response
				// stale before it arrives, so drop it rather than render it.
				if (this.request) {
					this.request.abort();
				}

				const bounds = this.map.getBounds();
				const zoom = this.map.getZoom();

				this.request = new AbortController();
				this.loading = true;
				this.error = undefined;

				axios
					.get('/api/geo/v1/feed', {
						signal: this.request.signal,
						params: Object.assign(
							{
								bbox: [
									bounds.getWest(),
									bounds.getSouth(),
									bounds.getEast(),
									bounds.getNorth(),
								]
									.map((v) => v.toFixed(6))
									.join(','),
								zoom: zoom,
							},
							this.dateParams()
						),
					})
					.then((res) => {
						this.request = undefined;
						this.loading = false;
						this.mode = res.data.mode;
						this.clusters = res.data.clusters || [];
						this.pins = this.groupByPosition(res.data.posts || []);
						this.render();
						this.hydrateSelection();
					})
					.catch((err) => {
						if (axios.isCancel(err) || err.name === 'CanceledError') {
							return;
						}

						this.request = undefined;
						this.loading = false;
						this.error = 'Could not load posts for this area';
					});
			},

			/**
			 * At city precision every post in a town shares one coordinate,
			 * so markers would stack invisibly. Collapse them into one pin
			 * that opens a gallery.
			 */
			groupByPosition(posts) {
				const groups = new Map();

				posts.forEach((post) => {
					const key = post.lat.toFixed(5) + ',' + post.lng.toFixed(5);

					if (!groups.has(key)) {
						groups.set(key, { lat: post.lat, lng: post.lng, posts: [] });
					}

					groups.get(key).posts.push(post);
				});

				return Array.from(groups.values());
			},

			render() {
				// Selection can change before the map exists, when a post is
				// opened from a link.
				if (!this.markers) {
					return;
				}

				this.markers.clearLayers();

				if (this.mode === 'clusters') {
					this.clusters.forEach(this.addCluster);

					return;
				}

				this.pins.forEach(this.addPin);
			},

			addCluster(cluster) {
				const size = this.clusterSize(cluster.count);

				const bubble = this.el('div', { className: 'geo-cluster__bubble' }, [
					this.el('span', { textContent: this.abbreviate(cluster.count) }),
				]);

				L.marker([cluster.lat, cluster.lng], {
					icon: L.divIcon({
						html: bubble,
						className: 'geo-cluster',
						iconSize: [size, size],
						iconAnchor: [size / 2, size / 2],
					}),
					keyboard: true,
					title: cluster.count + (cluster.count === 1 ? ' post' : ' posts'),
				})
					.on('click', () => {
						// One level past the clustering cutoff lands straight
						// on individual posts rather than another cluster.
						const target = Math.max(
							this.map.getZoom() + 3,
							(this.config.clusterMaxZoom || 12) + 1
						);

						this.map.setView(
							[cluster.lat, cluster.lng],
							Math.min(target, this.config.maxZoom || 18)
						);
					})
					.addTo(this.markers);
			},

			addPin(pin) {
				const cover = pin.posts[0];
				const active =
					this.selectedId !== undefined &&
					pin.posts.some((post) => post.id === this.selectedId);

				const thumb = this.el('img', {
					className: 'geo-pin__thumb',
					alt: cover.description || '',
					loading: 'lazy',
				});
				thumb.src = cover.thumbnail;
				thumb.onerror = () => {
					thumb.src = '/storage/no-preview.png';
				};

				const children = [thumb];

				if (cover.sensitive) {
					children.push(this.el('span', { className: 'geo-pin__cw' }, [
						this.el('i', { className: 'far fa-eye-slash' }),
					]));
				}

				if (pin.posts.length > 1) {
					children.push(
						this.el('span', {
							className: 'geo-pin__count',
							textContent: this.abbreviate(pin.posts.length),
						})
					);
				}

				const marker = L.marker([pin.lat, pin.lng], {
					icon: L.divIcon({
						html: this.el(
							'div',
							{
								className:
									'geo-pin__inner' + (active ? ' geo-pin__inner--active' : ''),
							},
							children
						),
						className: 'geo-pin',
						iconSize: [46, 46],
						iconAnchor: [23, 46],
						popupAnchor: [0, -46],
					}),
					keyboard: true,
					title: cover.place ? cover.place.name : '',
				});

				if (pin.posts.length > 1) {
					// Which of the posts here did they mean? Ask, then open.
					marker.bindPopup(() => this.buildPopup(pin), {
						minWidth: 232,
						maxWidth: 232,
						className: 'geo-popup',
						closeButton: true,
					});
				} else {
					marker.on('click', () => this.openPost(cover, pin.posts, 0));
				}

				marker.addTo(this.markers);
			},

			/**
			 * Built as DOM nodes, never innerHTML: captions, alt text and
			 * display names are all author supplied.
			 */
			buildPopup(pin) {
				const wrap = this.el('div', { className: 'geo-popup__body' });

				if (pin.posts[0].place) {
					wrap.appendChild(
						this.el('p', {
							className: 'geo-popup__place',
							textContent:
								pin.posts[0].place.name +
								(pin.posts[0].place.country ? ', ' + pin.posts[0].place.country : ''),
						})
					);
				}

				const grid = this.el('div', { className: 'geo-popup__grid' });

				pin.posts.slice(0, 9).forEach((post, index) => {
					const img = this.el('img', {
						alt: post.description || '',
						loading: 'lazy',
					});
					img.src = post.thumbnail;
					img.onerror = () => {
						img.src = '/storage/no-preview.png';
					};

					const link = this.el(
						'a',
						{
							className:
								'geo-popup__tile' +
								(post.sensitive ? ' geo-popup__tile--cw' : '') +
								(post.id === this.selectedId ? ' geo-popup__tile--active' : ''),
							title: post.account.acct ? '@' + post.account.acct : '',
						},
						[img]
					);

					// A real href, so the permalink is still there for a
					// middle click, a long press or a viewer without JS.
					link.href = post.url;
					link.addEventListener('click', (event) => {
						if (
							event.button !== 0 ||
							event.metaKey ||
							event.ctrlKey ||
							event.shiftKey ||
							event.altKey
						) {
							return;
						}

						event.preventDefault();
						this.openPost(post, pin.posts, index);
					});

					grid.appendChild(link);
				});

				wrap.appendChild(grid);

				if (pin.posts.length > 9) {
					const overflow = pin.posts.length - 9;
					const place = pin.posts[0].place;

					// Upstream already has a page listing everything at a
					// place, so send them there rather than paginating in a
					// 232px popup.
					if (place && place.url) {
						const more = this.el('a', {
							className: 'geo-popup__more',
							textContent: '+' + overflow + ' more at ' + place.name,
						});
						more.href = place.url;
						wrap.appendChild(more);
					} else {
						wrap.appendChild(
							this.el('p', {
								className: 'geo-popup__more',
								textContent: '+' + overflow + ' more',
							})
						);
					}
				}

				return wrap;
			},

			/**
			 * @param  post     the post to show
			 * @param  posts    every post at the same pin, for prev/next
			 * @param  index    which of them `post` is
			 * @param  options  history: false to adopt a state the history
			 *                  already holds, rather than pushing a new one
			 */
			openPost(post, posts, index, options) {
				const opts = options || {};

				this.gallery = posts && posts.length ? posts : [post];
				this.selectedIndex = index || 0;
				this.paneExpanded = false;

				if (this.map) {
					this.map.closePopup();
				}

				if (opts.history !== false) {
					this.writeHistory(post.id, false);
				}

				this.$nextTick(() => this.resizeMap(true));
			},

			closePost(options) {
				const opts = options || {};

				this.gallery = [];
				this.selectedIndex = 0;
				this.paneExpanded = false;

				if (opts.history !== false) {
					this.writeHistory(undefined, false);
				}

				this.$nextTick(() => this.resizeMap(false));
			},

			/**
			 * Prev/next within one pin. Replaces rather than pushes: walking a
			 * pin's posts should not make Back a dozen presses.
			 */
			step(delta) {
				const next = this.selectedIndex + delta;

				if (next < 0 || next >= this.gallery.length) {
					return;
				}

				this.selectedIndex = next;
				this.writeHistory(this.selectedId, true);
			},

			/**
			 * The pane and the map are siblings, so opening the pane genuinely
			 * shrinks the map rather than covering it. Leaflet has to be told,
			 * and the pin that was clicked has to be brought back into what is
			 * left of the viewport.
			 */
			resizeMap(pan) {
				if (!this.map) {
					return;
				}

				this.map.invalidateSize({ animate: false });

				if (!pan) {
					return;
				}

				const post = this.gallery[this.selectedIndex];

				if (post && isFinite(post.lat) && isFinite(post.lng)) {
					this.map.panTo([post.lat, post.lng]);
				}
			},

			/**
			 * A post opened from a link arrives as a bare id: no viewport has
			 * been fetched, so there is nothing yet to say where it is or what
			 * else shares its pin. Fill that in when a viewport containing it
			 * turns up.
			 */
			hydrateSelection() {
				if (this.gallery.length !== 1 || this.gallery[0].lat !== undefined) {
					return;
				}

				const found = this.galleryFor(this.selectedId);

				if (!found) {
					return;
				}

				this.gallery = found.posts;
				this.selectedIndex = found.index;
				this.resizeMap(true);
			},

			galleryFor(id) {
				for (let i = 0; i < this.pins.length; i++) {
					const index = this.pins[i].posts.findIndex((post) => post.id === id);

					if (index > -1) {
						return { posts: this.pins[i].posts, index: index };
					}
				}

				return null;
			},

			/**
			 * The post is gone, or its author has been muted. Viewports are
			 * cached for a couple of minutes per viewer, so refetching would
			 * hand the same pins straight back — drop them here instead.
			 */
			dropPost(id) {
				this.removePins((post) => post.id !== id);
				this.closePost();
			},

			dropAccount(accountId) {
				this.removePins((post) => !post.account || post.account.id !== accountId);
				this.closePost();
			},

			removePins(keep) {
				this.pins = this.pins
					.map((pin) => Object.assign({}, pin, { posts: pin.posts.filter(keep) }))
					.filter((pin) => pin.posts.length > 0);

				this.render();
			},

			onKeydown(event) {
				if (event.key !== 'Escape' || !this.hasSelection) {
					return;
				}

				// A dialog opened from the post owns Escape first — the
				// context menu and report modal are Bootstrap, the delete
				// confirmation is sweetalert.
				if (document.querySelector('.modal.show, .swal-overlay--show-modal')) {
					return;
				}

				// Escape in the comment box dismisses the mention menu. It
				// must not also throw away a half-written comment.
				const focused = document.activeElement;

				if (
					focused &&
					(focused.isContentEditable ||
						['INPUT', 'TEXTAREA', 'SELECT'].includes(focused.tagName))
				) {
					return;
				}

				this.closePost();
			},

			onPopState() {
				this.syncFromUrl({ history: false });
			},

			syncFromUrl(options) {
				const id = this.postIdFromUrl();

				if (id === this.selectedId) {
					return;
				}

				if (!id) {
					this.closePost(options);

					return;
				}

				const found = this.galleryFor(id);

				if (found) {
					this.openPost(found.posts[found.index], found.posts, found.index, options);

					return;
				}

				this.openPost({ id: id }, [{ id: id }], 0, options);
			},

			postIdFromUrl() {
				try {
					const id = new URL(window.location.href).searchParams.get(POST_PARAM);

					// Goes into an API path, so take digits and nothing else.
					return id && /^[0-9]+$/.test(id) ? id : undefined;
				} catch (e) {
					return undefined;
				}
			},

			writeHistory(id, replace) {
				try {
					const url = new URL(window.location.href);

					if (id) {
						url.searchParams.set(POST_PARAM, id);
					} else {
						url.searchParams.delete(POST_PARAM);
					}

					const target = url.pathname + url.search + url.hash;
					const current = window.location.pathname + window.location.search + window.location.hash;

					if (target === current) {
						return;
					}

					const state = { geoPost: id || null };

					if (replace) {
						window.history.replaceState(state, '', target);
					} else {
						window.history.pushState(state, '', target);
					}
				} catch (e) {
					// No history API. The pane still works, it just is not
					// linkable and Back leaves the page.
				}
			},

			dayToDate(day) {
				const date = new Date(this.dateOrigin);

				date.setDate(date.getDate() + day);

				return date;
			},

			dayIndexFor(date) {
				const day = Math.round((startOfDay(date) - this.dateOrigin) / DAY_MS);

				return Math.max(0, Math.min(this.spanDays, day));
			},

			presetFromDay(preset) {
				return this.dayIndexFor(preset.start(new Date(this.dateToday)));
			},

			/**
			 * Handles push each other rather than blocking. Blocking leaves
			 * whichever handle is on top unable to move when the two sit on
			 * the same day, which on a range input they regularly do.
			 */
			onHandle(which, event) {
				const day = Math.max(0, Math.min(this.spanDays, parseInt(event.target.value, 10)));

				if (isNaN(day)) {
					return;
				}

				if (which === 'from') {
					this.fromDay = day;

					if (this.toDay < day) {
						this.toDay = day;
					}
				} else {
					this.toDay = day;

					if (this.fromDay > day) {
						this.fromDay = day;
					}
				}

				this.onDateChange();
			},

			/**
			 * Clicking the track moves whichever handle the click belongs to.
			 * Standard slider behaviour, and the path that still works if a
			 * browser declines pointer events on a thumb pseudo-element.
			 */
			onTrackClick(event) {
				const rect = event.currentTarget.getBoundingClientRect();
				const usable = rect.width - HANDLE_PX;

				if (usable <= 0) {
					return;
				}

				const fraction = Math.max(
					0,
					Math.min(1, (event.clientX - rect.left - HANDLE_PX / 2) / usable)
				);
				const day = Math.round(fraction * this.spanDays);

				// Ordered by construction, so no pushing is needed: outside the
				// range the near end moves out, inside it the nearer end moves in.
				if (day <= this.fromDay) {
					this.fromDay = day;
				} else if (day >= this.toDay) {
					this.toDay = day;
				} else if (day - this.fromDay <= this.toDay - day) {
					this.fromDay = day;
				} else {
					this.toDay = day;
				}

				this.onDateChange();
			},

			applyPreset(key) {
				const preset = PRESETS.find((p) => p.key === key);

				if (!preset) {
					return;
				}

				this.toDay = this.spanDays;
				this.fromDay = preset.start ? this.presetFromDay(preset) : 0;

				this.onDateChange();
			},

			onDateChange() {
				this.scheduleFetch();
			},

			/**
			 * A handle parked at either end of the track means "no bound at
			 * all", not "bounded at the oldest post" — so the default range
			 * sends no parameters and asks exactly what it asked before this
			 * filter existed.
			 */
			dateParams() {
				const params = {};

				if (this.fromDay > 0) {
					params.from = this.isoDay(this.fromDate);
				}

				if (this.toDay < this.spanDays) {
					params.to = this.isoDay(this.toDate);
				}

				return params;
			},

			// Local calendar day. `toISOString()` would be the UTC one, which
			// is a different day for most of the world for part of each day.
			isoDay(date) {
				const pad = (n) => (n < 10 ? '0' + n : String(n));

				return (
					date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate())
				);
			},

			locate() {
				if (!navigator.geolocation) {
					this.error = 'Your browser will not share a location';

					return;
				}

				this.locating = true;

				navigator.geolocation.getCurrentPosition(
					(position) => {
						this.locating = false;
						this.map.setView(
							[position.coords.latitude, position.coords.longitude],
							Math.max(this.map.getZoom(), (this.config.clusterMaxZoom || 12) + 1)
						);
					},
					() => {
						this.locating = false;
						this.error = 'Could not get your location';
					},
					{ timeout: 10000, maximumAge: 300000 }
				);
			},

			restoreView() {
				const fallback = {
					lat: this.config.defaultLat != null ? this.config.defaultLat : 20,
					lng: this.config.defaultLng != null ? this.config.defaultLng : 0,
					zoom: this.config.defaultZoom || 3,
				};

				try {
					const saved = JSON.parse(window.localStorage.getItem(STORAGE_KEY));

					if (saved && isFinite(saved.lat) && isFinite(saved.lng) && isFinite(saved.zoom)) {
						return saved;
					}
				} catch (e) {
					// Private browsing, or a stale value from an older build.
				}

				return fallback;
			},

			persistView() {
				try {
					const center = this.map.getCenter();

					window.localStorage.setItem(
						STORAGE_KEY,
						JSON.stringify({
							lat: center.lat,
							lng: center.lng,
							zoom: this.map.getZoom(),
						})
					);
				} catch (e) {
					// Storage unavailable; the map still works, it just will
					// not reopen where the viewer left it.
				}
			},

			clusterSize(count) {
				if (count >= 1000) return 62;
				if (count >= 100) return 54;
				if (count >= 10) return 44;

				return 36;
			},

			abbreviate(count) {
				if (count >= 1000) {
					return Math.round(count / 100) / 10 + 'k';
				}

				return String(count);
			},

			el(tag, props, children) {
				const node = document.createElement(tag);

				Object.keys(props || {}).forEach((key) => {
					node[key] = props[key];
				});

				(children || []).forEach((child) => node.appendChild(child));

				return node;
			},
		},
	};
</script>
