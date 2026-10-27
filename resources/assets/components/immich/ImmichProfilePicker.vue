<template>
	<div class="immich-profile-picker d-flex align-items-start">
		<autocomplete
			ref="autocomplete"
			class="flex-grow-1"
			:search="search"
			:placeholder="placeholder"
			aria-label="Search accounts"
			:get-result-value="resultValue"
			:debounce-time="250"
			@submit="submit">
			<template #result="{ result, props }">
				<li v-bind="props" class="autocomplete-result">
					<div class="d-flex align-items-center">
						<img
							:src="result.avatar"
							class="rounded-circle border mr-2"
							width="24"
							height="24"
							onerror="this.onerror=null;this.src='/storage/avatars/default.png?v=0';">
						<span class="font-weight-bold">@{{ result.name }}</span>
					</div>
				</li>
			</template>
		</autocomplete>

		<button
			v-if="cancellable"
			type="button"
			class="btn btn-link btn-sm text-lighter ml-1"
			@click.prevent="$emit('cancel')">
			Cancel
		</button>
	</div>
</template>

<script type="text/javascript">
	/**
	 * Fork feature: Immich library. See docs/fork/IMMICH.md
	 *
	 * Find a Pixelfed account to map an Immich name to. Uses upstream's own
	 * tag search, so it offers exactly the accounts the composer's "Tag
	 * people" would — the ones a mapping could ever be used to tag.
	 */
	import Autocomplete from '@trevoreyre/autocomplete-vue';
	import '@trevoreyre/autocomplete-vue/dist/style.css';

	export default {
		components: {
			Autocomplete,
		},

		props: {
			placeholder: {
				type: String,
				default: 'Search for an account…',
			},

			cancellable: {
				type: Boolean,
				default: false,
			},
		},

		methods: {
			search(input) {
				const q = (input || '').trim();

				if (q.length < 2) {
					return [];
				}

				return axios
					.get('/api/compose/v0/search/tag', { params: { q } })
					.then((res) => (Array.isArray(res.data) ? res.data : []))
					.catch(() => []);
			},

			resultValue(result) {
				return '@' + result.name;
			},

			submit(result) {
				if (!result) {
					return;
				}

				this.$emit('select', result);
			},

			focus() {
				const input = this.$el.querySelector('input');

				if (input) {
					input.focus();
				}
			},
		},
	};
</script>

<style scoped>
	.immich-profile-picker >>> .autocomplete-input {
		padding-top: 6px;
		padding-bottom: 6px;
		font-size: 14px;
	}
</style>
