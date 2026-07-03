<script setup lang="ts">
import { computed, ref, useId } from 'vue'

const props = defineProps<{
  currentPage: number
  totalPages: number
}>()

const emit = defineEmits<{
  navigate: [page: number]
}>()

const previousPage = computed(() => props.currentPage - 1)
const nextPage = computed(() => props.currentPage + 1)
const hasPrevious = computed(() => props.currentPage > 1)
const hasNext = computed(() => props.currentPage < props.totalPages)

function go(page: number) {
  if (page >= 1 && page <= props.totalPages) {
    emit('navigate', page)
  }
}

// ── Jump-to-page ─────────────────────────────────────────
// Only worth showing when there are enough pages to make stepping tedious.
const showJump = computed(() => props.totalPages > 5)
const jumpId = useId()
const jumpValue = ref<number | null>(null)

function submitJump() {
  const n = Number(jumpValue.value)
  if (!Number.isFinite(n)) return
  const target = Math.min(Math.max(Math.trunc(n), 1), props.totalPages)
  jumpValue.value = null
  if (target !== props.currentPage) emit('navigate', target)
}
</script>

<template>
  <nav class="pagination is-centered" role="navigation" aria-label="pagination">
    <a
      class="pagination-previous"
      :class="{ 'is-disabled': !hasPrevious }"
      :aria-disabled="!hasPrevious"
      @click.prevent="go(previousPage)"
      >Previous</a
    >
    <a
      class="pagination-next"
      :class="{ 'is-disabled': !hasNext }"
      :aria-disabled="!hasNext"
      @click.prevent="go(nextPage)"
      >Next</a
    >
    <ul class="pagination-list">
      <!-- First page + ellipsis -->
      <li v-if="currentPage >= 3">
        <a class="pagination-link" @click.prevent="go(1)">1</a>
      </li>
      <li v-if="currentPage >= 3">
        <span class="pagination-ellipsis">&hellip;</span>
      </li>

      <!-- Previous page -->
      <li v-if="currentPage >= 2">
        <a class="pagination-link" @click.prevent="go(previousPage)">{{ previousPage }}</a>
      </li>

      <!-- Current page -->
      <li>
        <a class="pagination-link is-current" aria-current="page">{{ currentPage }}</a>
      </li>

      <!-- Next page -->
      <li v-if="hasNext">
        <a class="pagination-link" @click.prevent="go(nextPage)">{{ nextPage }}</a>
      </li>

      <!-- Ellipsis + last page -->
      <li v-if="currentPage <= totalPages - 2">
        <span class="pagination-ellipsis">&hellip;</span>
      </li>
      <li v-if="currentPage <= totalPages - 2">
        <a class="pagination-link" @click.prevent="go(totalPages)">{{ totalPages }}</a>
      </li>

      <!-- Inline jump-to-page: an extra "chip" in the list. novalidate so an
           out-of-range entry submits and is clamped by submitJump(). -->
      <li v-if="showJump">
        <form class="pagination-jump" novalidate @submit.prevent="submitJump">
          <input
            :id="jumpId"
            v-model.number="jumpValue"
            class="input pagination-jump-input"
            type="number"
            min="1"
            :max="totalPages"
            inputmode="numeric"
            :aria-label="`Go to page (1 to ${totalPages})`"
            placeholder="Go to…"
          />
        </form>
      </li>
    </ul>
  </nav>
</template>

<style scoped>
/* The form is just a wrapper so Enter submits — it shouldn't affect layout. */
.pagination-jump {
  display: flex;
  margin: 0;
}

/* Size the field like a pagination chip so it sits inline in the list. */
.pagination-jump-input {
  width: 6.5em;
  height: 2.5em;
  text-align: center;
}

/* Drop the number spinners so it reads as a page chip, not a stepper. */
.pagination-jump-input {
  appearance: textfield;
  -moz-appearance: textfield;
}
.pagination-jump-input::-webkit-outer-spin-button,
.pagination-jump-input::-webkit-inner-spin-button {
  -webkit-appearance: none;
  margin: 0;
}
</style>
