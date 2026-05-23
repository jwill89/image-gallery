<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useMediaTags } from '../composables/useMediaTags'
import { useGalleryStore } from '../stores/gallery'
import { useToastStore } from '../stores/toast'
import { useApi, hasAuthToken } from '../composables/useApi'
import { useFavoritesStore } from '../stores/favorites'
import TagMultiSelect from '../components/TagMultiSelect.vue'
import TagBadge from '../components/TagBadge.vue'
import TagShortcodeModal from '../components/TagShortcodeModal.vue'
import LoadingSpinner from '../components/LoadingSpinner.vue'

const props = defineProps<{
  mediaId: number
}>()

const router = useRouter()
const store = useGalleryStore()
const toastStore = useToastStore()
const api = useApi()
const favorites = useFavoritesStore()
const { tags, mediaItem, loading, loadFailed, fetchMediaAndTags, addTags, removeTag } = useMediaTags()

const showHelpModal = ref(false)
const showDeleteModal = ref(false)
const deleting = ref(false)
const authenticated = ref(hasAuthToken())
const mediaUrl = ref('')
const mediaReady = ref(false)
const selectedTagIds = ref<number[]>([])

const appliedTagIds = computed(() => tags.value.map(t => t.tag_id))

const formattedDate = computed(() => {
  if (!mediaItem.value?.file_time) return ''
  const date = new Date(mediaItem.value.file_time * 1000)
  return date.toLocaleString(undefined, {
    weekday: 'short', year: 'numeric', month: 'short', day: 'numeric',
    hour: '2-digit', minute: '2-digit', second: '2-digit', timeZoneName: 'short'
  })
})

const fullPath = computed(() => {
  if (!mediaItem.value) return ''
  return `/media/full/${mediaItem.value.file_name}`
})

const isVideoItem = computed(() => mediaItem.value?.media_type === 'video')

onMounted(load)
watch(() => props.mediaId, load)

async function load() {
  mediaUrl.value = ''
  mediaReady.value = false
  await fetchMediaAndTags(props.mediaId)
  if (mediaItem.value) {
    mediaUrl.value = `/media/full/${mediaItem.value.file_name}`
  }
}

function backToGallery() {
  router.back()
}

async function onAddTags() {
  if (selectedTagIds.value.length === 0) return
  await addTags(props.mediaId, [...selectedTagIds.value])
  selectedTagIds.value = []
}

async function onRemoveTag(tagId: number) {
  if (confirm('Are you sure you want to remove this tag?')) {
    await removeTag(props.mediaId, tagId)
  }
}

const isVideo = (url: string) => {
  const ext = url.split('.').pop()?.toLowerCase()
  return ext && ['mp4', 'webm', 'mov', 'avi', 'mkv'].includes(ext)
}

const currentIndex = computed(() => {
  return store.lastViewedItemIds.indexOf(props.mediaId)
})
const prevId = computed(() => {
  const idx = currentIndex.value
  return idx > 0 ? store.lastViewedItemIds[idx - 1] : null
})
const nextId = computed(() => {
  const idx = currentIndex.value
  return idx >= 0 && idx < store.lastViewedItemIds.length - 1 ? store.lastViewedItemIds[idx + 1] : null
})

function navigatePrev() {
  if (prevId.value != null) {
    router.replace({ name: 'media-tags', params: { id: prevId.value } })
  }
}

function navigateNext() {
  if (nextId.value != null) {
    router.replace({ name: 'media-tags', params: { id: nextId.value } })
  }
}

async function deleteMedia() {
  deleting.value = true
  try {
    await api.del(`/media/${props.mediaId}/`)
    toastStore.success('Media deleted successfully.')
    showDeleteModal.value = false

    // Remove from the gallery navigation list so arrow keys don't land on a dead item
    const idx = store.lastViewedItemIds.indexOf(props.mediaId)
    if (idx >= 0) {
      store.lastViewedItemIds.splice(idx, 1)
    }

    // Refresh totals since we removed an item
    await store.refreshTotals()

    // Navigate to the next item if available, otherwise go back
    if (nextId.value != null) {
      router.replace({ name: 'media-tags', params: { id: nextId.value } })
    } else if (prevId.value != null) {
      router.replace({ name: 'media-tags', params: { id: prevId.value } })
    } else {
      router.back()
    }
  } catch (e: any) {
    toastStore.error(e.message || 'Failed to delete media.')
  } finally {
    deleting.value = false
  }
}

function onGlobalKeydown(e: KeyboardEvent) {
  if (e.target instanceof HTMLInputElement || e.target instanceof HTMLTextAreaElement) return
  if (e.key === 'ArrowLeft') navigatePrev()
  else if (e.key === 'ArrowRight') navigateNext()
}

onMounted(() => window.addEventListener('keydown', onGlobalKeydown))
onUnmounted(() => window.removeEventListener('keydown', onGlobalKeydown))
</script>

<template>
  <section class="section">
    <div class="container">
      <LoadingSpinner v-if="loading && !mediaItem" />
      <div v-else-if="loadFailed && !mediaItem" class="has-text-centered py-6">
        <span class="icon is-large has-text-grey-light">
          <i class="fa-solid fa-circle-exclamation fa-3x"></i>
        </span>
        <p class="is-size-5 has-text-grey mt-4">Could not load media details.</p>
        <div class="buttons is-centered mt-4">
          <button class="button is-link" @click="load">
            <span class="icon"><i class="fa-solid fa-rotate-right"></i></span>
            <span>Retry</span>
          </button>
          <button class="button" @click="backToGallery">
            <span class="icon"><i class="fa-solid fa-backward"></i></span>
            <span>Back to Gallery</span>
          </button>
        </div>
      </div>
      <template v-else>
        <div class="columns">
          <div class="column is-three-fifths">
            <figure class="image tags-page-img">
              <div v-if="!mediaReady" class="media-placeholder">
                <span class="icon is-large has-text-grey">
                  <i class="fa-solid fa-spinner fa-spin fa-2x"></i>
                </span>
              </div>
              <video
                v-if="mediaUrl && isVideo(mediaUrl)"
                controls
                :src="mediaUrl"
                :class="['media-fade', { 'is-loaded': mediaReady, 'thumb-blur': store.blurThumbnails }]"
                @loadeddata="mediaReady = true"
              />
              <img
                v-else-if="mediaUrl"
                :src="mediaUrl"
                alt=""
                :class="['media-fade', { 'is-loaded': mediaReady, 'thumb-blur': store.blurThumbnails }]"
                @load="mediaReady = true"
              />
            </figure>
          </div>

          <div class="column">
            <div class="buttons mb-4">
              <button class="button is-link" @click="backToGallery">
                <span class="icon"><i class="fa-solid fa-backward"></i></span>
                <span>Back to Gallery</span>
              </button>
              <button class="button" :disabled="prevId == null" @click="navigatePrev" title="Previous (&larr;)">
                <span class="icon"><i class="fa-solid fa-arrow-left"></i></span>
              </button>
              <button class="button" :disabled="nextId == null" @click="navigateNext" title="Next (&rarr;)">
                <span class="icon"><i class="fa-solid fa-arrow-right"></i></span>
              </button>
              <button
                class="button"
                :class="favorites.isFavorite(mediaId) ? 'is-danger' : 'is-light'"
                @click="favorites.toggle(mediaId)"
                :title="favorites.isFavorite(mediaId) ? 'Remove from favorites' : 'Add to favorites'"
              >
                <span class="icon">
                  <i :class="favorites.isFavorite(mediaId) ? 'fa-solid fa-heart' : 'fa-regular fa-heart'"></i>
                </span>
              </button>
              <button
                v-if="authenticated"
                class="button is-danger is-outlined"
                @click="showDeleteModal = true"
                title="Delete this media"
              >
                <span class="icon"><i class="fa-solid fa-trash"></i></span>
              </button>
            </div>

            <!-- Media Details -->
            <h2 class="title is-5">Media Details</h2>
            <table class="table is-narrow is-fullwidth">
              <tbody>
                <tr>
                  <th>Date Added</th>
                  <td>{{ formattedDate }}</td>
                </tr>
                <tr>
                  <th>MD5 Hash</th>
                  <td><code>{{ mediaItem?.hash }}</code></td>
                </tr>
                <tr>
                  <th>Full Media</th>
                  <td><a :href="fullPath" target="_blank">View Full {{ isVideoItem ? 'Video' : 'Image' }} <i class="fa-solid fa-up-right-from-square fa-xs"></i></a></td>
                </tr>
              </tbody>
            </table>

            <hr />

            <!-- Add Tags -->
            <h3 class="title is-6">Add Tags</h3>
            <TagMultiSelect
              v-model="selectedTagIds"
              :exclude-tag-ids="appliedTagIds"
              placeholder="Search tags to add..."
              @submit="onAddTags"
            >
              <template #actions>
                <div class="control">
                  <button class="button is-primary" @click="onAddTags" :disabled="selectedTagIds.length === 0">
                    Add Tags
                  </button>
                </div>
              </template>
            </TagMultiSelect>
            <p class="help">
              Add tags. Multiple tags are allowed.
              <a @click.prevent="showHelpModal = true" style="cursor:pointer">Click here</a>
              to read more about tag categories, differentiated by colors.
            </p>

            <hr />

            <!-- Current Tags -->
            <h3 class="title is-6">Current Tags</h3>
            <div class="tags are-medium">
              <TagBadge
                v-for="tag in tags"
                :key="tag.tag_id"
                :tag-id="tag.tag_id"
                :tag-name="tag.tag_name"
                :category-id="tag.category_id"
                :removable="authenticated"
                @remove="onRemoveTag"
              />
              <span v-if="tags.length === 0" class="has-text-grey">No tags applied yet.</span>
            </div>
          </div>
        </div>
      </template>

      <!-- Shortcode Help Modal -->
      <div class="modal" :class="{ 'is-active': showHelpModal }">
        <div class="modal-background" @click="showHelpModal = false"></div>
        <TagShortcodeModal @close="showHelpModal = false" />
      </div>

      <!-- Delete Confirmation Modal -->
      <div class="modal" :class="{ 'is-active': showDeleteModal }">
        <div class="modal-background" @click="showDeleteModal = false"></div>
        <div class="modal-card">
          <header class="modal-card-head">
            <p class="modal-card-title">Confirm Deletion</p>
            <button class="delete" aria-label="close" @click="showDeleteModal = false"></button>
          </header>
          <section class="modal-card-body">
            <p>Are you sure you want to permanently delete this media item?</p>
            <p class="has-text-danger mt-2">
              <span class="icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
              This action cannot be undone.
            </p>
          </section>
          <footer class="modal-card-foot">
            <button class="button is-danger" :class="{ 'is-loading': deleting }" :disabled="deleting" @click="deleteMedia">
              <span class="icon"><i class="fa-solid fa-trash"></i></span>
              <span>Delete</span>
            </button>
            <button class="button" @click="showDeleteModal = false" :disabled="deleting">Cancel</button>
          </footer>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.media-placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 300px;
  background: rgba(255, 255, 255, 0.03);
  border-radius: 6px;
}

.media-fade {
  opacity: 0;
  transition: opacity 0.3s ease;
}

.media-fade.is-loaded {
  opacity: 1;
}
</style>
