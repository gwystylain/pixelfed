// Fork feature: geo feed. See docs/fork/GEO_FEED.md
//
// Own entry point rather than an SPA route, so the feature stays clear of
// upstream's router and component registry — the two files in resources/
// that a rebase is most likely to touch.

Vue.component(
    'geo-feed',
    require('./../components/geo/GeoFeed.vue').default
);
