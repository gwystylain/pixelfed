// Fork feature: Immich library. See docs/fork/IMMICH.md
//
// Settings → Immich. Loaded by resources/views/immich/settings.blade.php
// before upstream's settings.js, which creates the page's Vue root.
// The composer's half of the feature is imported by ComposeModal.vue
// instead, so it lands in the composer's own bundles.

Vue.component(
    'immich-settings',
    require('./../components/immich/ImmichSettings.vue').default
);
