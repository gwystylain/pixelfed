// Fork feature: geo feed. See docs/fork/GEO_FEED.md
//
// Makes upstream's SPA post components usable outside the SPA.
//
// The map's post pane renders upstream's own TimelineStatus.vue, context menu
// and modals, so a post opened from a pin behaves exactly like one opened in
// the feed. Those components are written against the SPA at /i/web and reach
// for two things a classic Blade page does not have: a Vuex store and a
// vue-router instance.
//
// Rather than reimplement the post UI, supply both. Neither pretends to be the
// real thing:
//
//   store   a copy of the subset of spa.js's store the post components read,
//           off the same localStorage keys — so a viewer who turned counts off
//           in the feed sees them off here too.
//
//   router  a shim. Every `$router.push` in those components goes to a profile
//           or a permalink, i.e. somewhere off this page, so a real navigation
//           is the correct behaviour rather than a fallback.
//
// No new libraries reach the page. Vue, Vuex, bootstrap-vue, vue-timeago,
// vue-blurhash, vue-carousel and vue-infinite-loading are all in vendor.js,
// which every page already loads, and components.js has installed the plugins.

import Vue from 'vue';
import Vuex from 'vuex';

const SETTINGS_PREFIX = 'pf_m2s.';

/**
 * spa.js reads the viewer's timeline preferences straight out of localStorage.
 * This is its `lss()`, rule for rule — including "anything that is not the
 * string 'true' is false" — so the two stores cannot disagree about a setting.
 */
function stored(name, fallback) {
	let value;

	try {
		value = window.localStorage.getItem(SETTINGS_PREFIX + name);
	} catch (e) {
		// Private browsing. Fall back rather than fail to boot.
		return fallback;
	}

	if (!value) {
		return fallback;
	}

	if (['pl', 'color-scheme'].includes(name)) {
		return value;
	}

	return ['true', true].includes(value);
}

Vue.use(Vuex);

export const store = new Vuex.Store({
	state: {
		version: 1,
		hideCounts: stored('hc', false),
		autoloadComments: stored('ac', true),
		newReactions: stored('nr', true),
		fixedHeight: stored('fh', false),
		profileLayout: stored('pl', 'grid'),
		showDMPrivacyWarning: stored('dmpwarn', true),
		relationships: {},
		emoji: [],
		colorScheme: stored('color-scheme', 'system'),
	},

	getters: {
		getVersion: (state) => state.version,
		getHideCounts: (state) => state.hideCounts,
		getAutoloadComments: (state) => state.autoloadComments,
		getNewReactions: (state) => state.newReactions,
		getFixedHeight: (state) => state.fixedHeight,
		getProfileLayout: (state) => state.profileLayout,
		getRelationship: (state) => (id) => state.relationships[id],
		getCustomEmoji: (state) => state.emoji,
		getColorScheme: (state) => state.colorScheme,
		getShowDMPrivacyWarning: (state) => state.showDMPrivacyWarning,
	},

	mutations: {
		updateRelationship(state, relationships) {
			relationships.forEach((relationship) => {
				Vue.set(state.relationships, relationship.id, relationship);
			});
		},

		updateCustomEmoji(state, emojis) {
			state.emoji = emojis;
		},
	},

	// spa.js also carries `set*` mutations for the timeline settings page and a
	// `setColorScheme` that rewrites `document.body.className`. Neither belongs
	// here: nothing on this page can reach the settings UI, and the map page's
	// body class is the layout's, not the SPA's.
});

Vue.prototype.$store = store;

let emojiRequested = false;

/**
 * Display names can contain custom emoji, which the profile hover card in the
 * comment thread resolves against this list. spa.js fetches it at boot; here
 * it waits until a post is actually opened, so browsing the map costs nothing.
 * Called from GeoPostPane, which is created once per post — hence the flag.
 */
export function ensureCustomEmoji() {
	if (emojiRequested) {
		return;
	}

	emojiRequested = true;

	axios
		.get('/api/v1/custom_emojis')
		.then((res) => store.commit('updateCustomEmoji', res.data))
		.catch(() => {
			// Emoji shortcodes render as their text. Nothing else needs this.
		});
}

/**
 * vue-router's surface, as far as upstream's post components use it.
 *
 * They push either a string path or `{ name, path, params }`; `params` carries
 * a component the destination route would have received as a prop, which is a
 * cache warm-up we cannot honour and do not need to. The path is always
 * absolute and always leaves this page.
 */
Vue.prototype.$router = {
	push(target) {
		const path = typeof target === 'string' ? target : target && target.path;

		if (!path) {
			return;
		}

		window.location.href = path;
	},

	// ContextMenu and PostEditModal read this to tell "already on the post
	// permalink" from "somewhere else". On the map it is always somewhere else.
	currentRoute: { name: undefined, params: {}, path: window.location.pathname },
};

Vue.prototype.$route = {
	name: undefined,
	params: {},
	query: {},
	path: window.location.pathname,
};
