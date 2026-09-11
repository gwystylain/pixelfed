// Fork feature: geo feed. See docs/fork/GEO_FEED.md
//
// Upstream's PostContent.vue registers `read-more` and `video-player` locally
// and then resolves its media presenters — `<photo-presenter>` and the three
// album presenters — globally, because spa.js registers them there. Vue 2 does
// not walk up the parent chain to resolve a component, so registering them on
// the pane would not do: they have to be global here too.
//
// Imported from GeoPostPane.vue rather than from geo.js, so the presenters
// land in the pane's chunk instead of on the map page's boot path.
//
// This is the media set spa.js registers, not only the four PostContent.vue
// currently reaches for. If upstream moves the video branch back to
// `<video-presenter>`, that is one fewer silent blank where a photo should be.

import Vue from 'vue';

Vue.component('photo-presenter', require('@/presenter/PhotoPresenter.vue').default);
Vue.component('video-presenter', require('@/presenter/VideoPresenter.vue').default);
Vue.component('photo-album-presenter', require('@/presenter/PhotoAlbumPresenter.vue').default);
Vue.component('video-album-presenter', require('@/presenter/VideoAlbumPresenter.vue').default);
Vue.component('mixed-album-presenter', require('@/presenter/MixedAlbumPresenter.vue').default);
