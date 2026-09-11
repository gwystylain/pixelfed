// Fork feature: geo feed. See docs/fork/GEO_FEED.md
//
// Own entry point rather than an SPA route, so the feature stays clear of
// upstream's router and component registry — the two files in resources/
// that a rebase is most likely to touch.

// Gives upstream's SPA post components the Vuex store and router they expect,
// so the map's post pane can be the real thing rather than a copy of it.
require('./geo/spa-bridge');

Vue.component(
    'geo-feed',
    require('./../components/geo/GeoFeed.vue').default
);
