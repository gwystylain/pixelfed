<template>
	<div class="geo-feed">
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
				<span v-else class="text-muted small">No posts in view</span>
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

		<div ref="map" class="geo-feed__map"></div>
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
	 */

	// Bundled lazily: a viewer who never opens the map never downloads it.
	let L = null;

	const STORAGE_KEY = 'pf.geo.lastView';
	const MOVE_DEBOUNCE_MS = 350;

	export default {
		data() {
			return {
				config: window._geoConfig || {},
				loading: false,
				locating: false,
				error: undefined,
				mode: 'clusters',
				clusters: [],
				pins: [],
			};
		},

		computed: {
			totalInView() {
				if (this.mode === 'clusters') {
					return this.clusters.reduce((sum, c) => sum + c.count, 0);
				}

				return this.pins.reduce((sum, pin) => sum + pin.posts.length, 0);
			},
		},

		async mounted() {
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
						params: {
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
					})
					.then((res) => {
						this.request = undefined;
						this.loading = false;
						this.mode = res.data.mode;
						this.clusters = res.data.clusters || [];
						this.pins = this.groupByPosition(res.data.posts || []);
						this.render();
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
						html: this.el('div', { className: 'geo-pin__inner' }, children),
						className: 'geo-pin',
						iconSize: [46, 46],
						iconAnchor: [23, 46],
						popupAnchor: [0, -46],
					}),
					keyboard: true,
					title: cover.place ? cover.place.name : '',
				});

				marker.bindPopup(() => this.buildPopup(pin), {
					minWidth: 232,
					maxWidth: 232,
					className: 'geo-popup',
					closeButton: true,
				});

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

				pin.posts.slice(0, 9).forEach((post) => {
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
							className: 'geo-popup__tile' + (post.sensitive ? ' geo-popup__tile--cw' : ''),
							title: post.account.acct ? '@' + post.account.acct : '',
						},
						[img]
					);
					link.href = post.url;

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
