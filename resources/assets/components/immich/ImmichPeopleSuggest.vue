<template>
	<div v-if="visible" class="immich-people border-bottom">
		<div class="px-4 py-2">
			<div class="d-flex align-items-center">
				<i class="far fa-user-friends text-primary mr-2"></i>
				<span class="small text-lighter">
					In {{ photoCount > 1 ? 'these photos' : 'this photo' }}, according to Immich
				</span>
				<button
					type="button"
					class="btn btn-link btn-sm text-lighter p-0 ml-auto"
					aria-label="Hide suggestions"
					@click.prevent="dismissed = true">
					<i class="far fa-times"></i>
				</button>
			</div>

			<div v-for="person in people" :key="person.key" class="immich-people__row">
				<div class="d-flex align-items-center">
					<template v-if="person.profile">
						<img
							:src="person.profile.avatar"
							class="rounded-circle border mr-2"
							width="24"
							height="24"
							onerror="this.onerror=null;this.src='/storage/avatars/default.png?v=0';">
						<span class="small flex-grow-1 immich-people__name">
							<span class="font-weight-bold">{{ person.name }}</span>
							<span class="text-lighter">@{{ person.profile.name }}</span>
						</span>
						<span v-if="isTagged(person)" class="small text-success font-weight-bold text-nowrap">
							<i class="far fa-check"></i> Tagged
						</span>
						<button
							v-else
							type="button"
							class="btn btn-primary btn-sm immich-people__btn"
							:disabled="full"
							@click.prevent="tag(person)">
							Tag
						</button>
					</template>

					<template v-else>
						<span class="immich-people__blank rounded-circle border mr-2">
							<i class="far fa-user text-lighter"></i>
						</span>
						<span class="small flex-grow-1 immich-people__name">
							<span class="font-weight-bold">{{ person.name }}</span>
							<span class="text-lighter">&middot; not linked to an account</span>
						</span>
						<button
							v-if="linking !== person.key"
							type="button"
							class="btn btn-outline-secondary btn-sm immich-people__btn"
							@click.prevent="linking = person.key">
							Link
						</button>
					</template>
				</div>

				<div v-if="linking === person.key" class="mt-1">
					<immich-profile-picker
						cancellable
						:placeholder="'Which account is ' + person.name + '?'"
						@select="link(person, $event)"
						@cancel="linking = null" />
					<p class="text-lighter mb-0 mt-1" style="font-size: 11px">
						Remembered for next time. Change it in Settings &rarr; Immich.
					</p>
				</div>
			</div>

			<div class="d-flex align-items-center mt-2">
				<button
					v-if="untagged.length > 1 && !full"
					type="button"
					class="btn btn-outline-primary btn-sm immich-people__btn mr-2"
					@click.prevent="tagAll">
					Tag all {{ untagged.length }}
				</button>
				<span v-if="full" class="text-lighter" style="font-size: 11px">That's the {{ limit }} person tag limit.</span>
				<span v-if="error" class="text-danger" style="font-size: 11px">{{ error }}</span>
			</div>
		</div>
	</div>
</template>

<script type="text/javascript">
	/**
	 * Fork feature: Immich library. See docs/fork/IMMICH.md
	 *
	 * Offers to tag the people Immich recognised in the photos picked from
	 * it, through the author's name → account links.
	 *
	 * Purely a shortcut into upstream's own "Tag people": a tag added here is
	 * pushed into the composer's `taggedUsernames` in the shape upstream's
	 * tag search returns, so it shows up on that page, can be removed there,
	 * and is published by the same code. Nothing is tagged without a click.
	 */
	import ImmichProfilePicker from './ImmichProfilePicker.vue';

	export default {
		components: {
			'immich-profile-picker': ImmichProfilePicker,
		},

		props: {
			// The composer's `files`: [{ file: File, editState }]
			files: {
				type: Array,
				default: () => [],
			},

			// The composer's `taggedUsernames`
			tagged: {
				type: Array,
				default: () => [],
			},

			// Upstream's composer stops at ten.
			limit: {
				type: Number,
				default: 10,
			},
		},

		data() {
			return {
				people: [],
				photoCount: 0,
				dismissed: false,
				linking: null,
				error: null,
				// Links made here, by name key, so they survive a recompute.
				linked: {},
			};
		},

		computed: {
			visible() {
				return !this.dismissed && this.people.length > 0;
			},

			full() {
				return this.tagged.length >= this.limit;
			},

			untagged() {
				return this.people.filter((p) => p.profile && !this.isTagged(p));
			},
		},

		watch: {
			// The File objects are not reactive, but the array holding them
			// is: this runs whenever a photo is added, removed or reordered.
			files: {
				immediate: true,
				handler() {
					this.collect();
				},
			},
		},

		methods: {
			collect() {
				const byKey = {};
				let photos = 0;

				(this.files || []).forEach((entry) => {
					const immich = entry && entry.file && entry.file.immich;

					if (!immich) {
						return;
					}

					photos++;

					(immich.people || []).forEach((person) => {
						if (!person || !person.key) {
							return;
						}

						const existing = byKey[person.key];
						const profile = this.linked[person.key] || person.profile || null;

						if (!existing) {
							byKey[person.key] = { key: person.key, name: person.name, profile };
						} else if (!existing.profile && profile) {
							existing.profile = profile;
						}
					});
				});

				this.photoCount = photos;
				this.people = Object.values(byKey).sort((a, b) => {
					// Linked people first: they are the ones a click can tag.
					if (!!a.profile !== !!b.profile) {
						return a.profile ? -1 : 1;
					}

					return a.name.localeCompare(b.name);
				});
			},

			isTagged(person) {
				return !!person.profile && this.tagged.some((t) => String(t.id) === String(person.profile.id));
			},

			tag(person) {
				if (!person.profile || this.isTagged(person) || this.full) {
					return;
				}

				this.$emit('tag', Object.assign({}, person.profile));
			},

			tagAll() {
				this.untagged.slice(0, this.limit - this.tagged.length).forEach((person) => this.tag(person));
			},

			link(person, profile) {
				this.error = null;

				axios
					.put('/api/immich/v1/mappings', { name: person.name, profile_id: profile.id })
					.then((res) => {
						const linkedProfile = res.data.profile || profile;

						this.$set(this.linked, person.key, linkedProfile);
						this.linking = null;
						this.collect();
					})
					.catch((err) => {
						this.error = (err.response && err.response.data && err.response.data.message) || 'Could not save that link.';
					});
			},
		},
	};
</script>

<style scoped>
	/*
	 * Kept in the component, like GeoSuggest's: this renders inside upstream's
	 * composer, which never loads a fork stylesheet.
	 */
	.immich-people__row {
		margin-top: 6px;
	}

	.immich-people__name {
		min-width: 0;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
		padding-right: 8px;
	}

	.immich-people__blank {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		width: 24px;
		height: 24px;
		flex-shrink: 0;
		font-size: 11px;
	}

	.immich-people__btn {
		font-size: 11px;
		padding: 2px 10px;
		text-transform: uppercase;
		font-weight: 700;
	}
</style>
