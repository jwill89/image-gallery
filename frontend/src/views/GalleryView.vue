<script setup lang="ts">
import {
  ref,
  computed,
  watch,
  onMounted,
  onUnmounted,
  onActivated,
  onDeactivated,
  nextTick,
} from 'vue'
import { useRouter } from 'vue-router'
import { useGalleryData } from '../composables/useGalleryData'
import { useGalleryStore } from '../stores/gallery'
import { useApi } from '../composables/useApi'
import { endpoints } from '../api/endpoints'
import type { MediaItem, MediaPage } from '../types'
import GalleryCard from '../components/GalleryCard.vue'
import PaginationBar from '../components/PaginationBar.vue'
import LoadingSpinner from '../components/LoadingSpinner.vue'
import { prefetchThumbnails } from '../composables/usePrefetch'

// Named so App.vue can keep this view alive (preserving accumulated items and
// scroll position when returning from a media detail page).
defineOptions({ name: 'GalleryView' })

/** Build the media-listing path for a page + optional tag filter. */
function listUrl(page: number, perPage: number, tags?: string): string {
  if (tags === 'untagged') return endpoints.media.untagged(page, perPage)
  if (tags) return endpoints.media.withTags(tags, page, perPage)
  return endpoints.media.page(page, perPage)
}

const props = defineProps<{
  page: number
  perPage: number
  tags?: string
}>()

const router = useRouter()
const store = useGalleryStore()
const api = useApi()
const { items, totalPages, loading, loadFailed, fetchPage } = useGalleryData()

const INFINITE_BATCH_SIZE = 40
// Viewport y (just below the fixed navbar) used to decide which card is "at the
// top" and where to park the current page after an entry-time previous-page load.
const CONTENT_TOP = 60
// Infinite scroll is a global preference (toggle in the navbar), not a URL param.
const isInfiniteScroll = computed(() => store.infiniteScroll)
const accumulatedItems = ref<MediaItem[]>([])
const currentBatchPage = ref(1)
const loadingMore = ref(false)
const allLoaded = ref(false)
const scrollSentinel = ref<HTMLElement | null>(null)
const topSentinel = ref<HTMLElement | null>(null)
const gridEl = ref<HTMLElement | null>(null)
let observer: IntersectionObserver | null = null
let topObserver: IntersectionObserver | null = null
const topLoading = ref(false)
// Whether this (keep-alive'd) view is the one currently on screen.
const isActive = ref(true)
// The page whose items are at the top of the viewport while scrolling.
const currentInfinitePage = ref(1)
// The page the infinite list starts loading from, so turning infinite scroll on
// from a paged position resumes there instead of jumping back to the top.
const batchStartPage = ref(1)
// Scroll position to restore when returning from a media detail page (the view
// is kept alive, so its accumulated items — and their real heights — survive).
let savedScrollY = 0
let restoreOnActivate = false
// When entering infinite scroll partway down, the first previous-page load parks
// the entry page at the top (rather than anchoring), so the URL/view stay on it.
let bootstrapTopLoad = false

const displayItems = computed(() => (isInfiniteScroll.value ? accumulatedItems.value : items.value))

/** The grid route for a given page (infinite scroll always uses 40-item pages). */
function gridRoute(page: number) {
  return props.tags
    ? { name: 'media-with-tags', params: { page, perPage: INFINITE_BATCH_SIZE, tags: props.tags } }
    : { name: 'media', params: { page, perPage: INFINITE_BATCH_SIZE } }
}

/**
 * The 40-item batch page holding the first item of the current paged view — so a
 * paged position (which may use a different per-page size) maps to the right
 * spot in the infinite list.
 */
function startPageFromPaged() {
  return Math.floor(((props.page - 1) * props.perPage) / INFINITE_BATCH_SIZE) + 1
}

function updateStoreItemIds() {
  store.lastViewedItemIds = displayItems.value.map((i) => i.media_id)
}

async function loadPage() {
  if (isInfiniteScroll.value) {
    const start = batchStartPage.value
    accumulatedItems.value = []
    currentBatchPage.value = start
    allLoaded.value = false
    loadingMore.value = false
    await fetchPage(start, INFINITE_BATCH_SIZE, props.tags)
    accumulatedItems.value = [...items.value]
    if (start >= totalPages.value) {
      allLoaded.value = true
    } else {
      currentBatchPage.value = start + 1
    }
  } else {
    await fetchPage(props.page, props.perPage, props.tags)
    // Pre-cache thumbnails for the next page
    void prefetchAdjacentPage(props.page + 1, props.perPage, props.tags)
  }
  updateStoreItemIds()
}

async function prefetchAdjacentPage(page: number, perPage: number, tags?: string) {
  if (page < 1 || page > totalPages.value) return

  // Skip the extra round-trip on metered/slow connections — prefetching the
  // next page (to warm its thumbnails) isn't worth the data there.
  const conn = (
    navigator as unknown as { connection?: { saveData?: boolean; effectiveType?: string } }
  ).connection
  if (conn?.saveData || /(^|-)2g$/.test(conn?.effectiveType ?? '')) return

  try {
    const data = await api.get<MediaPage>(listUrl(page, perPage, tags))
    if (data?.items.length) {
      prefetchThumbnails(data.items)
    }
  } catch {
    // Prefetch is best-effort — don't disrupt the user
  }
}

async function loadNextBatch() {
  if (loadingMore.value || allLoaded.value) return
  loadingMore.value = true
  try {
    const data = await api.get<MediaPage>(
      listUrl(currentBatchPage.value, INFINITE_BATCH_SIZE, props.tags),
    )
    const newItems = data?.items ?? []
    accumulatedItems.value = [...accumulatedItems.value, ...newItems]
    const maxPages = data?.total_pages ?? 1
    if (currentBatchPage.value >= maxPages || newItems.length === 0) {
      allLoaded.value = true
    } else {
      currentBatchPage.value++
    }
    updateStoreItemIds()
  } catch (e) {
    console.error('Failed to load more items:', e)
  } finally {
    loadingMore.value = false
  }
}

// Load the page *above* the current top (when the infinite list started partway
// down) and prepend it, anchoring the scroll so the view doesn't jump.
async function loadPrevBatch() {
  if (topLoading.value || batchStartPage.value <= 1 || accumulatedItems.value.length === 0) return
  topLoading.value = true
  const prevPage = batchStartPage.value - 1
  try {
    const data = await api.get<MediaPage>(listUrl(prevPage, INFINITE_BATCH_SIZE, props.tags))
    const prevItems = data?.items ?? []
    if (prevItems.length === 0) {
      batchStartPage.value = 1
      return
    }
    // Anchor on the current first card so prepending doesn't shift what's on screen.
    const bootstrap = bootstrapTopLoad
    bootstrapTopLoad = false
    const anchorEl = gridEl.value?.children[0] as HTMLElement | undefined
    const beforeTop = anchorEl?.getBoundingClientRect().top ?? 0
    accumulatedItems.value = [...prevItems, ...accumulatedItems.value]
    batchStartPage.value = prevPage
    await nextTick()
    if (anchorEl) {
      const nowTop = anchorEl.getBoundingClientRect().top
      // Entry: park the page we came in on at the top. Scroll-up: keep the view put.
      window.scrollBy(0, bootstrap ? nowTop - CONTENT_TOP : nowTop - beforeTop)
    }
    updateStoreItemIds()
  } catch (e) {
    console.error('Failed to load previous items:', e)
  } finally {
    topLoading.value = false
  }
}

function setupObserver() {
  observer?.disconnect()
  const el = scrollSentinel.value
  if (!el) return
  observer = new IntersectionObserver(
    (entries) => {
      if (entries[0].isIntersecting) void loadNextBatch()
    },
    { rootMargin: '400px' },
  )
  observer.observe(el)
}

function setupTopObserver() {
  topObserver?.disconnect()
  const el = topSentinel.value
  if (!el) return
  topObserver = new IntersectionObserver(
    (entries) => {
      if (entries[0].isIntersecting) void loadPrevBatch()
    },
    // A small margin so it fires on approach but the anchored scroll then pushes
    // it clear of the viewport (no cascade of loads).
    { rootMargin: '200px' },
  )
  topObserver.observe(el)
}

watch(scrollSentinel, setupObserver)
watch(topSentinel, setupTopObserver)

// ── Infinite-scroll position → URL sync ─────────────────────
// While scrolling in infinite mode, reflect the page whose items are at the top
// of the viewport in the URL (via router.replace). The reload watch skips these
// page-only changes and scrollBehavior keeps the scroll put, so this is purely
// a position marker that also lets "turn infinite scroll off" resume the right
// page and keeps the URL honest.

let scrollRaf = 0

/** Page index of the topmost card currently visible below the navbar. */
function computeTopPage(): number {
  const grid = gridEl.value
  if (!grid || grid.children.length === 0) return batchStartPage.value
  const cards = grid.children
  // Cards are in document order, so `rect.bottom` increases with index — binary
  // search for the first card still visible below the navbar.
  let lo = 0
  let hi = cards.length - 1
  let first = cards.length - 1
  while (lo <= hi) {
    const mid = (lo + hi) >> 1
    if ((cards[mid] as HTMLElement).getBoundingClientRect().bottom > CONTENT_TOP) {
      first = mid
      hi = mid - 1
    } else {
      lo = mid + 1
    }
  }
  return batchStartPage.value + Math.floor(first / INFINITE_BATCH_SIZE)
}

function onScroll() {
  if (!isInfiniteScroll.value) return
  // Near the top: pull in earlier pages (the observer bootstraps the first one).
  if (window.scrollY < 300) void loadPrevBatch()
  if (scrollRaf) return
  scrollRaf = requestAnimationFrame(() => {
    scrollRaf = 0
    const page = computeTopPage()
    if (page !== currentInfinitePage.value) {
      currentInfinitePage.value = page
      void router.replace(gridRoute(page))
    }
  })
}

function onKeydown(e: KeyboardEvent) {
  if (isInfiniteScroll.value || loading.value) return
  if (
    e.target instanceof HTMLInputElement ||
    e.target instanceof HTMLTextAreaElement ||
    e.target instanceof HTMLSelectElement
  )
    return

  if (e.key === 'ArrowLeft' && props.page > 1) {
    onNavigate(props.page - 1)
  } else if (e.key === 'ArrowRight' && props.page < totalPages.value) {
    onNavigate(props.page + 1)
  }
}

function addListeners() {
  window.addEventListener('keydown', onKeydown)
  window.addEventListener('scroll', onScroll, { passive: true })
}
function removeListeners() {
  window.removeEventListener('keydown', onKeydown)
  window.removeEventListener('scroll', onScroll)
}

onMounted(() => {
  if (isInfiniteScroll.value) {
    batchStartPage.value = startPageFromPaged()
    currentInfinitePage.value = batchStartPage.value
    bootstrapTopLoad = batchStartPage.value > 1
  }
  void loadPage()
})
onActivated(() => {
  isActive.value = true
  addListeners()
  setupObserver()
  setupTopObserver()
  // Returning from a media detail page: restore the scroll position we left at.
  if (restoreOnActivate) {
    restoreOnActivate = false
    const y = savedScrollY
    requestAnimationFrame(() => window.scrollTo(0, y))
  }
})
onDeactivated(() => {
  isActive.value = false
  removeListeners()
  observer?.disconnect()
  topObserver?.disconnect()
})
onUnmounted(() => {
  removeListeners()
  observer?.disconnect()
  topObserver?.disconnect()
})

// Reload only on genuine list changes. In infinite mode a page-only change is
// our own scroll-position URL sync (router.replace) and must not reload.
watch(
  () => [props.page, props.perPage, props.tags] as const,
  (n, o) => {
    if (isInfiniteScroll.value && n[0] !== o[0] && n[1] === o[1] && n[2] === o[2]) return
    void loadPage()
  },
)

// The Media nav asks for a fresh gallery. In paged mode the page-change watch
// already reloads; in infinite mode a page-only change is skipped, so reset here.
watch(
  () => store.galleryResetSeq,
  () => {
    if (!isActive.value || !isInfiniteScroll.value) return
    restoreOnActivate = false
    bootstrapTopLoad = false
    batchStartPage.value = 1
    currentInfinitePage.value = 1
    void loadPage()
    window.scrollTo({ top: 0 })
  },
)

// React to the navbar infinite-scroll toggle.
watch(
  () => store.infiniteScroll,
  (on) => {
    if (!isActive.value) return
    if (on) {
      // Entering infinite scroll: resume from the page we were viewing.
      batchStartPage.value = startPageFromPaged()
      currentInfinitePage.value = batchStartPage.value
      bootstrapTopLoad = batchStartPage.value > 1
      if (props.page !== batchStartPage.value || props.perPage !== INFINITE_BATCH_SIZE) {
        void router.replace(gridRoute(batchStartPage.value))
      }
    }
    // Off: props.page already reflects the position (kept in sync while
    // scrolling), so the paged load lands on the right page.
    void loadPage()
    window.scrollTo({ top: 0 })
  },
)

function onNavigate(page: number) {
  if (props.tags) {
    void router.push({
      name: 'media-with-tags',
      params: { page, perPage: props.perPage, tags: props.tags },
    })
  } else {
    void router.push({
      name: 'media',
      params: { page, perPage: props.perPage },
    })
  }
}

function onCardClick(id: number) {
  // Remember where we are so returning from the detail page restores the scroll.
  if (isInfiniteScroll.value) {
    savedScrollY = window.scrollY
    restoreOnActivate = true
  }
  void router.push({
    name: 'media-tags',
    params: { id },
  })
}
</script>

<template>
  <section class="section">
    <div class="gallery-container">
      <LoadingSpinner v-if="loading" />

      <div v-else-if="loadFailed || displayItems.length === 0" class="has-text-centered py-6">
        <span class="icon is-large has-text-grey-light">
          <i class="fa-solid fa-images fa-3x" />
        </span>
        <p class="is-size-5 has-text-grey mt-4">
          {{ loadFailed ? 'Could not load the gallery. Please try again.' : 'No items found.' }}
        </p>
        <button v-if="loadFailed" class="button is-indigo mt-4" @click="loadPage">
          <span class="icon"><i class="fa-solid fa-rotate-right" /></span>
          <span>Retry</span>
        </button>
      </div>

      <div v-else>
        <PaginationBar
          v-if="!isInfiniteScroll"
          :current-page="page"
          :total-pages="totalPages"
          @navigate="onNavigate"
        />
        <hr v-if="!isInfiniteScroll" />

        <div style="min-height: 75vh">
          <div
            v-if="isInfiniteScroll && batchStartPage > 1"
            ref="topSentinel"
            class="has-text-centered py-5"
          >
            <span class="icon is-large has-text-grey"
              ><i class="fa-solid fa-spinner fa-spin fa-2x"
            /></span>
          </div>
          <div ref="gridEl" class="gallery-grid">
            <GalleryCard
              v-for="item in displayItems"
              :key="item.media_id"
              :item="item"
              @click="onCardClick"
            />
          </div>
        </div>

        <div
          v-if="isInfiniteScroll && !allLoaded"
          ref="scrollSentinel"
          class="has-text-centered py-5"
        >
          <span class="icon is-large has-text-grey"
            ><i class="fa-solid fa-spinner fa-spin fa-2x"
          /></span>
        </div>

        <p v-if="isInfiniteScroll && allLoaded" class="has-text-centered has-text-grey py-4">
          All items loaded
        </p>

        <hr v-if="!isInfiniteScroll" />
        <PaginationBar
          v-if="!isInfiniteScroll"
          :current-page="page"
          :total-pages="totalPages"
          @navigate="onNavigate"
        />
      </div>
    </div>
  </section>
</template>

<style scoped>
.gallery-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 200px));
  gap: 8px;
  justify-content: center;
}

@media (min-width: 769px) {
  .gallery-grid {
    grid-template-columns: repeat(auto-fill, minmax(160px, 200px));
  }
}

@media (min-width: 1200px) {
  .gallery-grid {
    grid-template-columns: repeat(auto-fill, minmax(170px, 200px));
  }
}
</style>
