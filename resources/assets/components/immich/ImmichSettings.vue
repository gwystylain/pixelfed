<template>
	<div class="immich-settings">
		<!-- Connection -->
		<section class="mb-5">
			<h5 class="font-weight-bold">Connection</h5>

			<div v-if="loading" class="py-4 text-center">
				<b-spinner small />
			</div>

			<template v-else>
				<div v-if="connection.connected && !editing" class="card shadow-none border">
					<div class="card-body">
						<p class="mb-1">
							<i class="far fa-check-circle text-success mr-1"></i>
							Connected to
							<span class="font-weight-bold">Immich<template v-if="connection.server_version"> {{ connection.server_version }}</template></span>
							at <code>{{ connection.url }}</code>
						</p>
						<p v-if="connection.user && (connection.user.name || connection.user.email)" class="mb-1 text-muted small">
							As {{ connection.user.name }}<template v-if="connection.user.email"> ({{ connection.user.email }})</template>
						</p>
						<p class="mb-3 text-muted small">
							API key ending <code>{{ connection.key_hint }}</code><template v-if="connection.verified_at"> &middot; checked {{ formatDate(connection.verified_at) }}</template>
						</p>

						<div v-if="connection.missing_required && connection.missing_required.length" class="alert alert-warning small">
							The key is missing
							<strong>{{ connection.missing_required.join(', ') }}</strong>,
							so photos cannot be picked yet. Add
							{{ connection.missing_required.length === 1 ? 'it' : 'them' }}
							to the key in Immich (or create a new key, below), then press <em>Check again</em>.
						</div>

						<table class="table table-sm small mb-3">
							<thead>
								<tr>
									<th>Permission</th>
									<th>Used for</th>
									<th class="text-right">Status</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="perm in permissionRows" :key="perm.name">
									<td><code>{{ perm.name }}</code></td>
									<td>
										{{ perm.purpose }}
										<span v-if="perm.required" class="badge badge-light border ml-1">required</span>
									</td>
									<td class="text-right text-nowrap">
										<span v-if="connection.permissions[perm.name] === true" class="text-success">
											<i class="far fa-check"></i> Allowed
										</span>
										<span v-else-if="connection.permissions[perm.name] === false" :class="perm.required ? 'text-danger' : 'text-muted'">
											<i class="far fa-times"></i> Missing
										</span>
										<span v-else class="text-muted" title="Could not be checked: the library has no photo to try it on.">
											<i class="far fa-question"></i> Unchecked
										</span>
									</td>
								</tr>
							</tbody>
						</table>

						<p v-if="checkError" class="text-danger small">{{ checkError }}</p>

						<button class="btn btn-outline-primary btn-sm font-weight-bold" :disabled="busy" @click.prevent="check">
							<b-spinner v-if="busy === 'check'" small class="mr-1" />
							Check again
						</button>
						<button class="btn btn-outline-secondary btn-sm font-weight-bold ml-1" :disabled="!!busy" @click.prevent="startEditing">
							Change address or key
						</button>
						<button class="btn btn-outline-danger btn-sm font-weight-bold ml-1" :disabled="!!busy" @click.prevent="disconnect">
							Disconnect
						</button>
					</div>
				</div>

				<form v-else class="card shadow-none border" @submit.prevent="save">
					<div class="card-body">
						<div class="form-group">
							<label for="immichUrl" class="font-weight-bold small">Immich server address</label>
							<input
								id="immichUrl"
								v-model="form.url"
								type="text"
								class="form-control"
								:class="{ 'is-invalid': errors.url }"
								:placeholder="config.default_url || 'http://192.168.1.10:2283'"
								autocomplete="off"
								spellcheck="false">
							<div v-if="errors.url" class="invalid-feedback">{{ errors.url }}</div>
							<small class="form-text text-muted">
								The address you open Immich at in a browser. It is this Pixelfed server that connects to it, not your
								browser, so a local network address works as long as the two servers can reach each other.
								<template v-if="config.host_restricted">This instance only allows particular Immich servers.</template>
							</small>
						</div>

						<div class="form-group">
							<label for="immichKey" class="font-weight-bold small">API key</label>
							<input
								id="immichKey"
								v-model="form.api_key"
								type="password"
								class="form-control"
								:class="{ 'is-invalid': errors.api_key }"
								:placeholder="connection.connected ? 'Leave blank to keep the current key' : 'Paste the key from Immich'"
								autocomplete="off"
								spellcheck="false">
							<div v-if="errors.api_key" class="invalid-feedback">{{ errors.api_key }}</div>
							<small class="form-text text-muted">
								Stored encrypted on this server and never sent back to your browser.
								See <a href="#immich-key-help" @click="showHelp = true">how to create one</a> below.
							</small>
						</div>

						<button type="submit" class="btn btn-primary btn-sm font-weight-bold" :disabled="!!busy">
							<b-spinner v-if="busy === 'save'" small class="mr-1" />
							{{ connection.connected ? 'Save' : 'Connect' }}
						</button>
						<button
							v-if="connection.connected"
							type="button"
							class="btn btn-link btn-sm text-muted"
							:disabled="!!busy"
							@click.prevent="editing = false">
							Cancel
						</button>
					</div>
				</form>
			</template>
		</section>

		<!-- Instructions -->
		<section id="immich-key-help" class="mb-5">
			<h5 class="font-weight-bold">
				<a href="#" class="text-dark text-decoration-none" @click.prevent="showHelp = !showHelp">
					<i class="far fa-fw" :class="showHelp ? 'fa-chevron-down' : 'fa-chevron-right'"></i>
					Creating the API key in Immich
				</a>
			</h5>

			<div v-show="showHelp" class="immich-settings__help">
				<ol class="pl-3">
					<li>Open Immich in your browser and sign in as the account whose photos you want to post.</li>
					<li>Click your profile picture in the top right, then <strong>Account Settings</strong>.</li>
					<li>Open <strong>API Keys</strong> and press <strong>New API Key</strong>.</li>
					<li>Name it something you will recognise later, such as <em>Pixelfed</em>.</li>
					<li>
						In the permission list, leave <strong>all</strong> unticked and tick only these:
						<table class="table table-sm table-bordered small mt-2 mb-2">
							<tbody>
								<tr v-for="perm in permissionRows" :key="'help-' + perm.name">
									<td class="text-nowrap"><code>{{ perm.name }}</code></td>
									<td>{{ perm.help }}</td>
									<td class="text-nowrap">
										<span v-if="perm.required" class="badge badge-primary">Required</span>
										<span v-else-if="perm.recommended" class="badge badge-info">Recommended</span>
										<span v-else class="badge badge-light border">Optional</span>
									</td>
								</tr>
							</tbody>
						</table>
					</li>
					<li>Press <strong>Create</strong>, then copy the key. Immich only shows it once.</li>
					<li>Paste it above with your server's address, and press <strong>Connect</strong>.</li>
				</ol>

				<p class="small text-muted mb-1">
					Every one of these permissions only reads. Nothing here can change, move or delete anything in your
					Immich library, and Pixelfed never uploads to it.
				</p>
				<p class="small text-muted mb-0">
					Older Immich versions have no permission list and every key gets full access to the account.
					That still works here, but the key can do far more than Pixelfed needs; updating Immich and
					creating a limited key is the better option.
				</p>
			</div>
		</section>

		<!-- People -->
		<section class="mb-4">
			<h5 class="font-weight-bold">People</h5>
			<p class="text-muted small">
				When a photo you pick from Immich has people in it that Immich has recognised and named, the composer
				offers to tag them. Link each name to the Pixelfed account it belongs to. Names are matched ignoring case,
				so every face group in Immich with the same name is covered by one link.
			</p>

			<div v-if="peopleLoading" class="py-3 text-center">
				<b-spinner small />
			</div>

			<template v-else>
				<div v-if="peopleError" class="alert alert-warning small">
					Could not load people from Immich: {{ peopleError }}
				</div>
				<p v-else-if="!peopleState.connected" class="text-muted small">
					Connect Immich above to see the people it has recognised. You can still add names by hand below.
				</p>

				<div v-if="people.length" class="d-flex align-items-center mb-2">
					<input
						v-model="filter"
						type="search"
						class="form-control form-control-sm mr-2"
						placeholder="Find a name…"
						style="max-width: 260px">
					<div class="custom-control custom-switch small">
						<input id="immichUnlinked" v-model="unlinkedOnly" type="checkbox" class="custom-control-input">
						<label class="custom-control-label" for="immichUnlinked">Only unlinked</label>
					</div>
					<span class="ml-auto small text-muted">{{ linkedCount }} of {{ people.length }} linked</span>
				</div>

				<div class="list-group mb-3">
					<div
						v-for="person in visiblePeople"
						:key="person.key"
						class="list-group-item d-flex align-items-center immich-settings__person">
						<img
							v-if="person.thumbnail"
							:src="person.thumbnail"
							class="rounded-circle border mr-3 immich-settings__face"
							width="36"
							height="36"
							loading="lazy"
							alt="">
						<span v-else class="rounded-circle border mr-3 immich-settings__face immich-settings__face--blank">
							<i class="far fa-user text-lighter"></i>
						</span>

						<span class="font-weight-bold immich-settings__name">{{ person.name }}</span>

						<div class="ml-auto d-flex align-items-center immich-settings__target">
							<template v-if="linking === person.key">
								<immich-profile-picker
									ref="picker"
									cancellable
									@select="link(person.name, $event)"
									@cancel="linking = null" />
							</template>
							<template v-else-if="person.mapping && person.mapping.profile">
								<i class="far fa-long-arrow-right text-lighter mr-2"></i>
								<img
									:src="person.mapping.profile.avatar"
									class="rounded-circle border mr-2"
									width="24"
									height="24"
									onerror="this.onerror=null;this.src='/storage/avatars/default.png?v=0';">
								<span class="mr-3">@{{ person.mapping.profile.name }}</span>
								<button class="btn btn-link btn-sm p-0 mr-2" @click.prevent="startLinking(person.key)">Change</button>
								<button class="btn btn-link btn-sm p-0 text-danger" @click.prevent="unlink(person.mapping)">Remove</button>
							</template>
							<template v-else-if="person.mapping">
								<span class="small text-muted mr-2">Linked account is no longer available</span>
								<button class="btn btn-link btn-sm p-0 mr-2" @click.prevent="startLinking(person.key)">Change</button>
								<button class="btn btn-link btn-sm p-0 text-danger" @click.prevent="unlink(person.mapping)">Remove</button>
							</template>
							<button
								v-else
								class="btn btn-outline-primary btn-sm font-weight-bold"
								@click.prevent="startLinking(person.key)">
								Link to account
							</button>
						</div>
					</div>

					<div v-if="people.length && !visiblePeople.length" class="list-group-item text-muted small">
						No names match.
					</div>
				</div>

				<button
					v-if="filteredPeople.length > visiblePeople.length"
					class="btn btn-outline-secondary btn-sm btn-block mb-3"
					@click.prevent="showAll = true">
					Show all {{ filteredPeople.length }}
				</button>

				<template v-if="peopleState.unmatched.length">
					<h6 class="font-weight-bold mt-4">Links for names Immich no longer has</h6>
					<p class="text-muted small">
						Still used if a photo turns up with one of these names, for instance after renaming a person
						back in Immich.
					</p>
					<div class="list-group mb-3">
						<div v-for="mapping in peopleState.unmatched" :key="'u' + mapping.id" class="list-group-item d-flex align-items-center">
							<span class="font-weight-bold">{{ mapping.name }}</span>
							<span class="ml-auto d-flex align-items-center">
								<template v-if="mapping.profile">
									<i class="far fa-long-arrow-right text-lighter mr-2"></i>
									<span class="mr-3">@{{ mapping.profile.name }}</span>
								</template>
								<span v-else class="small text-muted mr-3">Account no longer available</span>
								<button class="btn btn-link btn-sm p-0 text-danger" @click.prevent="unlink(mapping)">Remove</button>
							</span>
						</div>
					</div>
				</template>

				<h6 class="font-weight-bold mt-4">Add a name by hand</h6>
				<p class="text-muted small">For a name that is not in the list, spelled as it is in Immich.</p>
				<div class="d-flex align-items-start flex-wrap immich-settings__manual">
					<input
						v-model="manual.name"
						type="text"
						class="form-control form-control-sm mr-2 mb-2"
						placeholder="Name in Immich"
						maxlength="191"
						style="max-width: 220px">
					<immich-profile-picker
						v-if="manual.name.trim()"
						:key="manualPickerKey"
						class="mb-2"
						@select="link(manual.name, $event, true)" />
				</div>
				<p v-if="mappingError" class="text-danger small">{{ mappingError }}</p>
			</template>
		</section>
	</div>
</template>

<script type="text/javascript">
	/**
	 * Fork feature: Immich library. See docs/fork/IMMICH.md
	 *
	 * Settings → Immich. The connection, how to make the key, and which Immich
	 * names are which Pixelfed accounts.
	 */
	import ImmichProfilePicker from './ImmichProfilePicker.vue';

	/** How many people to render before "Show all". Libraries get large. */
	const INITIAL_PEOPLE = 100;

	const PERMISSIONS = {
		'asset.read': {
			purpose: 'Browsing and searching your library',
			help: 'List and search your photos, and read who is in them and where they were taken.',
		},
		'asset.view': {
			purpose: 'Thumbnails, and Immich\'s JPEG copy of HEIC/RAW photos',
			help: 'Show thumbnails in the picker, and fetch Immich\'s JPEG rendering of photos this instance cannot take as they are.',
		},
		'asset.download': {
			purpose: 'Fetching the original photo',
			help: 'Fetch the original file — full resolution, with the GPS that puts it on the photo map.',
		},
		'person.read': {
			purpose: 'People suggestions, and the list below',
			help: 'Read the names of the people Immich has recognised, for tag suggestions and for linking names here.',
		},
		'album.read': {
			purpose: 'The album filter in the picker',
			help: 'List your albums, so the picker can show one album at a time.',
		},
		'user.read': {
			purpose: 'Showing which Immich account is connected',
			help: 'Read your Immich account\'s name, so this page can show which account the key belongs to.',
		},
	};

	export default {
		components: {
			'immich-profile-picker': ImmichProfilePicker,
		},

		data() {
			const config = window._immichConfig || {};

			return {
				config,
				loading: true,
				busy: null,
				editing: false,
				showHelp: false,
				connection: { connected: false },
				form: { url: '', api_key: '' },
				errors: {},
				checkError: null,

				peopleLoading: true,
				peopleState: { connected: false, people: [], unmatched: [] },
				peopleError: null,
				filter: '',
				unlinkedOnly: false,
				showAll: false,
				linking: null,
				mappingError: null,
				manual: { name: '' },
				manualPickerKey: 0,
			};
		},

		computed: {
			permissionRows() {
				const required = this.config.required || ['asset.read', 'asset.view', 'asset.download'];
				const optional = this.config.optional || ['person.read', 'album.read', 'user.read'];

				return required.concat(optional).map((name) => ({
					name,
					required: required.includes(name),
					recommended: name === 'person.read',
					purpose: (PERMISSIONS[name] || {}).purpose || '',
					help: (PERMISSIONS[name] || {}).help || '',
				}));
			},

			people() {
				return this.peopleState.people || [];
			},

			linkedCount() {
				return this.people.filter((p) => p.mapping && p.mapping.profile).length;
			},

			filteredPeople() {
				const q = this.filter.trim().toLowerCase();

				return this.people.filter((p) => {
					if (this.unlinkedOnly && p.mapping) {
						return false;
					}

					return !q || p.name.toLowerCase().includes(q);
				});
			},

			visiblePeople() {
				return this.showAll ? this.filteredPeople : this.filteredPeople.slice(0, INITIAL_PEOPLE);
			},
		},

		mounted() {
			this.loadConnection();
			this.loadPeople();
		},

		methods: {
			loadConnection() {
				this.loading = true;

				return axios
					.get('/api/immich/v1/connection')
					.then((res) => {
						this.applyConnection(res.data);
					})
					.finally(() => {
						this.loading = false;
					});
			},

			applyConnection(data) {
				this.connection = Object.assign({ permissions: {} }, data);
				this.editing = false;
				this.showHelp = !data.connected || (data.missing_required && data.missing_required.length > 0);
				this.form.url = data.url || this.config.default_url || '';
				this.form.api_key = '';
			},

			loadPeople() {
				this.peopleLoading = true;
				this.peopleError = null;

				return axios
					.get('/api/immich/v1/people')
					.then((res) => {
						this.peopleState = Object.assign({ people: [], unmatched: [] }, res.data);
						this.peopleError = res.data.error || null;
					})
					.catch((err) => {
						this.peopleError = this.message(err);
					})
					.finally(() => {
						this.peopleLoading = false;
					});
			},

			startEditing() {
				this.form.url = this.connection.url || '';
				this.form.api_key = '';
				this.errors = {};
				this.editing = true;
			},

			save() {
				this.busy = 'save';
				this.errors = {};

				axios
					.put('/api/immich/v1/connection', {
						url: this.form.url,
						api_key: this.form.api_key || null,
					})
					.then((res) => {
						this.applyConnection(res.data);
						this.loadPeople();
					})
					.catch((err) => {
						const errors = (err.response && err.response.data && err.response.data.errors) || {};

						this.errors = {
							url: errors.url ? errors.url[0] : null,
							api_key: errors.api_key ? errors.api_key[0] : null,
						};

						if (!this.errors.url && !this.errors.api_key) {
							this.errors.url = this.message(err);
						}
					})
					.finally(() => {
						this.busy = null;
					});
			},

			check() {
				this.busy = 'check';
				this.checkError = null;

				axios
					.post('/api/immich/v1/connection/check')
					.then((res) => {
						this.applyConnection(res.data);
						this.loadPeople();
					})
					.catch((err) => {
						this.checkError = this.message(err);
					})
					.finally(() => {
						this.busy = null;
					});
			},

			disconnect() {
				if (!window.confirm('Disconnect Immich? Your name links are kept, and work again if you reconnect.')) {
					return;
				}

				this.busy = 'disconnect';

				axios
					.delete('/api/immich/v1/connection')
					.then((res) => {
						this.applyConnection(res.data);
						this.loadPeople();
					})
					.finally(() => {
						this.busy = null;
					});
			},

			startLinking(key) {
				this.linking = key;
				this.mappingError = null;

				this.$nextTick(() => {
					const picker = Array.isArray(this.$refs.picker) ? this.$refs.picker[0] : this.$refs.picker;

					if (picker) {
						picker.focus();
					}
				});
			},

			link(name, profile, manual = false) {
				this.mappingError = null;

				axios
					.put('/api/immich/v1/mappings', { name, profile_id: profile.id })
					.then((res) => {
						const mapping = res.data;
						const person = this.people.find((p) => p.key === mapping.key);

						if (person) {
							person.mapping = mapping;
						} else {
							const unmatched = this.peopleState.unmatched.filter((m) => m.key !== mapping.key);
							unmatched.push(mapping);
							this.peopleState.unmatched = unmatched;
						}

						this.linking = null;

						if (manual) {
							this.manual.name = '';
							this.manualPickerKey++;
						}
					})
					.catch((err) => {
						this.mappingError = this.message(err);
					});
			},

			unlink(mapping) {
				axios
					.delete('/api/immich/v1/mappings/' + encodeURIComponent(mapping.id))
					.then(() => {
						const person = this.people.find((p) => p.mapping && p.mapping.id === mapping.id);

						if (person) {
							person.mapping = null;
						}

						this.peopleState.unmatched = this.peopleState.unmatched.filter((m) => m.id !== mapping.id);
					})
					.catch((err) => {
						this.mappingError = this.message(err);
					});
			},

			message(err) {
				const data = err && err.response && err.response.data;

				return (data && data.message) || (err && err.message) || 'Something went wrong.';
			},

			formatDate(iso) {
				try {
					return new Date(iso).toLocaleString();
				} catch (e) {
					return iso;
				}
			},
		},
	};
</script>

<style scoped>
	.immich-settings__face {
		object-fit: cover;
		flex-shrink: 0;
	}

	.immich-settings__face--blank {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		width: 36px;
		height: 36px;
	}

	.immich-settings__name {
		min-width: 0;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
		margin-right: 12px;
	}

	.immich-settings__target {
		flex-shrink: 0;
	}

	.immich-settings__target .immich-profile-picker {
		width: 260px;
	}

	.immich-settings__manual .immich-profile-picker {
		width: 260px;
	}

	@media (max-width: 575px) {
		.immich-settings__person {
			flex-wrap: wrap;
		}

		.immich-settings__target {
			width: 100%;
			margin-top: 8px;
		}

		.immich-settings__target .immich-profile-picker,
		.immich-settings__manual .immich-profile-picker {
			width: 100%;
		}
	}
</style>
