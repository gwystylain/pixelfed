<template>
	<div class="geo-pane">
		<div class="geo-pane__bar">
			<button
				type="button"
				class="btn btn-link geo-pane__icon"
				title="Close post"
				aria-label="Close post"
				@click="$emit('close')">
				<i class="far fa-times"></i>
			</button>

			<div class="geo-pane__bar-nav">
				<template v-if="count > 1">
					<button
						type="button"
						class="btn btn-link geo-pane__icon"
						title="Previous post at this pin"
						aria-label="Previous post at this pin"
						:disabled="position < 1"
						@click="$emit('prev')">
						<i class="far fa-chevron-left"></i>
					</button>

					<span class="small text-muted">{{ position + 1 }} of {{ count }}</span>

					<button
						type="button"
						class="btn btn-link geo-pane__icon"
						title="Next post at this pin"
						aria-label="Next post at this pin"
						:disabled="position + 1 >= count"
						@click="$emit('next')">
						<i class="far fa-chevron-right"></i>
					</button>
				</template>
			</div>

			<button
				type="button"
				class="btn btn-link geo-pane__icon d-md-none"
				:title="expanded ? 'Show the map' : 'Hide the map'"
				:aria-label="expanded ? 'Show the map' : 'Hide the map'"
				@click="$emit('toggle-expand')">
				<i class="far" :class="expanded ? 'fa-compress-alt' : 'fa-expand-alt'"></i>
			</button>

			<a
				v-if="post"
				:href="post.url"
				class="btn btn-link geo-pane__icon"
				title="Open this post on its own page"
				aria-label="Open this post on its own page">
				<i class="far fa-external-link"></i>
			</a>
		</div>

		<div class="geo-pane__scroll">
			<div v-if="error" class="card shadow-sm" style="border-radius: 15px;">
				<div class="card-body">
					<p class="text-center mb-2">
						<i class="far fa-exclamation-triangle fa-2x text-lighter"></i>
					</p>
					<p class="text-center lead font-weight-bold mb-2">Cannot show this post</p>
					<p class="text-center text-muted small mb-0">
						It may have been deleted, or you may not have permission to see it.
					</p>
				</div>
			</div>

			<status-placeholder v-else-if="!isLoaded" />

			<status
				v-else
				:key="post.id + ':fui:' + forceUpdateIdx"
				:status="post"
				:profile="user"
				v-on:like="likeStatus()"
				v-on:unlike="unlikeStatus()"
				v-on:share="shareStatus()"
				v-on:unshare="unshareStatus()"
				v-on:menu="openContextMenu()"
				v-on:counter-change="counterChange"
				v-on:likes-modal="openLikesModal()"
				v-on:shares-modal="openSharesModal()"
				v-on:follow="follow()"
				v-on:unfollow="unfollow()"
				v-on:bookmark="handleBookmark()"
				v-on:comment-likes-modal="openCommentLikesModal"
				v-on:handle-report="handleReport"
				v-on:mod-tools="handleModTools()"
				/>
		</div>

		<context-menu
			v-if="isLoaded"
			ref="contextMenu"
			:status="shadowStatus"
			:profile="user"
			v-on:moderate="commitModeration"
			v-on:delete="handleGone"
			v-on:report-modal="handleReport"
			v-on:edit="handleEdit"
			v-on:muted="handleMuted"
			v-on:unfollow="handleUnfollowed"
			v-on:pinned="handlePinned(true)"
			v-on:unpinned="handlePinned(false)"
		/>

		<likes-modal
			v-if="showLikesModal"
			ref="likesModal"
			:status="likesModalPost"
			:profile="user"
		/>

		<shares-modal
			v-if="showSharesModal"
			ref="sharesModal"
			:status="shadowStatus"
			:profile="user"
		/>

		<report-modal
			v-if="isLoaded"
			ref="reportModal"
			:key="'geo-report:' + reportedStatusId"
			:status="reportedStatus"
		/>

		<post-edit-modal
			v-if="isLoaded"
			ref="editModal"
			v-on:update="mergeUpdatedPost"
		/>
	</div>
</template>

<script type="text/javascript">
	/**
	 * Fork feature: geo feed. See docs/fork/GEO_FEED.md
	 *
	 * One post, opened from a map pin, rendered by upstream's own timeline
	 * status component so that liking, commenting, sharing, reporting and
	 * editing behave exactly as they do in the feed rather than approximately.
	 *
	 * The event wiring is the union of what upstream's Post.vue and
	 * Timeline.vue handle: TimelineStatus and ContextMenu each emit events
	 * only one of those two listens for, and an unhandled emit is a menu item
	 * that silently does nothing. See GEO_FEED.md, "The post pane".
	 *
	 * The Vuex store and `$router` these components expect come from
	 * resources/assets/js/geo/spa-bridge.js.
	 */

	// Registers the media presenters PostContent.vue resolves globally. Must
	// come before anything below renders, which importing it here guarantees.
	import './../../js/geo/post-presenters';

	import { ensureCustomEmoji } from './../../js/geo/spa-bridge';
	import Status from '@/partials/TimelineStatus.vue';
	import StatusPlaceholder from '@/partials/StatusPlaceholder.vue';
	import ContextMenu from '@/partials/post/ContextMenu.vue';
	import LikesModal from '@/partials/post/LikeModal.vue';
	import SharesModal from '@/partials/post/ShareModal.vue';
	import ReportModal from '@/partials/modal/ReportPost.vue';
	import PostEditModal from '@/partials/post/PostEditModal.vue';

	export default {
		components: {
			status: Status,
			'status-placeholder': StatusPlaceholder,
			'context-menu': ContextMenu,
			'likes-modal': LikesModal,
			'shares-modal': SharesModal,
			'report-modal': ReportModal,
			'post-edit-modal': PostEditModal,
		},

		props: {
			postId: {
				type: String,
				required: true,
			},

			// Position within the posts sharing this pin, for the prev/next
			// control: at city precision one pin routinely holds a dozen.
			position: {
				type: Number,
				default: 0,
			},

			count: {
				type: Number,
				default: 1,
			},

			expanded: {
				type: Boolean,
				default: false,
			},
		},

		data() {
			return {
				user: window._sharedData.user,
				isLoaded: false,
				error: false,
				post: undefined,
				forceUpdateIdx: 0,
				showLikesModal: false,
				likesModalPost: {},
				showSharesModal: false,
				reportedStatus: {},
				reportedStatusId: 0,
			};
		},

		computed: {
			// A reblog carries the post it shares; every reaction belongs to
			// the latter. The map only pins local posts, so this should never
			// fire — but the components downstream assume it has been done.
			shadowStatus() {
				if (!this.post) {
					return undefined;
				}

				return this.post.reblog ? this.post.reblog : this.post;
			},
		},

		created() {
			ensureCustomEmoji();
			this.fetchPost();
		},

		methods: {
			fetchPost() {
				axios
					.get('/api/pixelfed/v1/statuses/' + this.postId)
					.then((res) => {
						if (!res.data || !res.data.id || !res.data.account) {
							this.error = true;

							return;
						}

						this.post = res.data;
						this.fetchRelationship();
					})
					.catch(() => {
						this.error = true;
					});
			},

			/**
			 * The hover card in the comment thread reads relationships out of
			 * the store, so this one goes there rather than staying local.
			 */
			fetchRelationship() {
				if (this.post.account.id == this.user.id) {
					this.fetchState();

					return;
				}

				axios
					.get('/api/pixelfed/v1/accounts/relationships', {
						params: { 'id[]': this.post.account.id },
					})
					.then((res) => {
						if (res.data && res.data.length) {
							this.$store.commit('updateRelationship', res.data);
						}

						this.fetchState();
					})
					.catch(() => {
						// A missing relationship costs a follow button state,
						// not the post.
						this.fetchState();
					});
			},

			fetchState() {
				axios
					.get('/api/v2/statuses/' + this.post.id + '/state')
					.then((res) => {
						this.post.favourited = res.data.liked;
						this.post.reblogged = res.data.shared;
						this.post.bookmarked = res.data.bookmarked;

						if (!this.post.favourites_count && this.post.favourited) {
							this.post.favourites_count = 1;
						}

						this.isLoaded = true;
					})
					.catch(() => {
						this.error = true;
					});
			},

			likeStatus() {
				const count = this.post.favourites_count;

				this.post.favourites_count = count + 1;
				this.post.favourited = !this.post.favourited;

				axios.post('/api/v1/statuses/' + this.post.id + '/favourite').catch(() => {
					this.post.favourites_count = count;
					this.post.favourited = false;
				});
			},

			unlikeStatus() {
				const count = this.post.favourites_count;

				this.post.favourites_count = count - 1;
				this.post.favourited = !this.post.favourited;

				axios.post('/api/v1/statuses/' + this.post.id + '/unfavourite').catch(() => {
					this.post.favourites_count = count;
					this.post.favourited = true;
				});
			},

			shareStatus() {
				const count = this.post.reblogs_count;

				this.post.reblogs_count = count + 1;
				this.post.reblogged = !this.post.reblogged;

				axios.post('/api/v1/statuses/' + this.post.id + '/reblog').catch(() => {
					this.post.reblogs_count = count;
					this.post.reblogged = false;
				});
			},

			unshareStatus() {
				const count = this.post.reblogs_count;

				this.post.reblogs_count = count - 1;
				this.post.reblogged = !this.post.reblogged;

				axios.post('/api/v1/statuses/' + this.post.id + '/unreblog').catch(() => {
					this.post.reblogs_count = count;
					this.post.reblogged = true;
				});
			},

			handleBookmark() {
				const post = this.shadowStatus;

				axios
					.post('/i/bookmark', { item: post.id })
					.then(() => {
						post.bookmarked = !post.bookmarked;
					})
					.catch(() => {
						this.$bvToast.toast('Cannot bookmark post at this time.', {
							title: 'Bookmark Error',
							variant: 'danger',
							autoHideDelay: 5000,
						});
					});
			},

			follow() {
				const post = this.shadowStatus;

				axios
					.post('/api/v1/accounts/' + post.account.id + '/follow')
					.then((res) => {
						this.$store.commit('updateRelationship', [res.data]);
						this.user.following_count++;
						post.account.followers_count++;
					})
					.catch(() => {
						swal('Oops!', 'An error occurred when attempting to follow this account.', 'error');
					});
			},

			unfollow() {
				const post = this.shadowStatus;

				axios
					.post('/api/v1/accounts/' + post.account.id + '/unfollow')
					.then((res) => {
						this.$store.commit('updateRelationship', [res.data]);
						this.user.following_count--;
						post.account.followers_count--;
					})
					.catch(() => {
						swal('Oops!', 'An error occurred when attempting to unfollow this account.', 'error');
					});
			},

			counterChange(type) {
				const post = this.shadowStatus;

				switch (type) {
					case 'comment-increment':
						post.reply_count = post.reply_count + 1;
						break;

					case 'comment-decrement':
						post.reply_count = post.reply_count - 1;
						break;
				}
			},

			openContextMenu() {
				this.$nextTick(() => {
					this.$refs.contextMenu.open();
				});
			},

			handleModTools() {
				this.$nextTick(() => {
					this.$refs.contextMenu.openModMenu();
				});
			},

			openLikesModal() {
				this.likesModalPost = this.shadowStatus;
				this.showLikesModal = true;
				this.$nextTick(() => {
					this.$refs.likesModal.open();
				});
			},

			openCommentLikesModal(post) {
				this.likesModalPost = post.reblog ? post.reblog : post;
				this.showLikesModal = true;
				this.$nextTick(() => {
					this.$refs.likesModal.open();
				});
			},

			openSharesModal() {
				this.showSharesModal = true;
				this.$nextTick(() => {
					this.$refs.sharesModal.open();
				});
			},

			handleReport(post) {
				this.reportedStatusId = post.id;
				this.$nextTick(() => {
					this.reportedStatus = post;
					this.$refs.reportModal.open();
				});
			},

			handleEdit(status) {
				this.$refs.editModal.show(status);
			},

			mergeUpdatedPost(post) {
				this.post = post;
				this.$nextTick(() => {
					this.forceUpdateIdx++;
				});
			},

			handlePinned(pinned) {
				this.post.pinned = pinned;
			},

			/**
			 * Deleted, or archived — upstream emits `delete` for both. Either
			 * way this is no longer a public post at these coordinates, so the
			 * pin goes with it.
			 */
			handleGone() {
				this.$emit('gone');
			},

			/**
			 * Muted from the context menu. The post stays open — it was asked
			 * for by name — but map pins are filtered per viewer, so the
			 * account's other pins have to go.
			 */
			handleMuted(post) {
				this.$emit('filtered', post.account.id);
			},

			/**
			 * Unfollowing changes nothing on the map: it shows public posts,
			 * not a follow graph. Only the viewer's own count moves.
			 */
			handleUnfollowed() {
				this.user.following_count--;
			},

			commitModeration(type) {
				const post = this.shadowStatus;

				switch (type) {
					case 'addcw':
						post.sensitive = true;
						break;

					case 'remcw':
						post.sensitive = false;
						break;

					case 'unlist':
						this.$emit('gone');
						break;

					case 'spammer':
						this.$emit('filtered', post.account.id);
						break;
				}
			},
		},
	};
</script>
