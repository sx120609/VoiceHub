<template>
  <div ref="containerEl" class="marquee-container">
    <div ref="contentEl" :class="{ scrolling }" class="marquee-content">
      <span class="text-item">{{ text }}</span>
      <span v-if="scrolling" class="text-item">{{ text }}</span>
    </div>
  </div>
</template>

<script setup>
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue'

const props = defineProps({
  text: {
    type: String,
    required: true
  },
  speed: {
    type: Number,
    default: 40 // pixels per second
  },
  activated: {
    type: Boolean,
    default: true // 默认为true保持向后兼容性
  }
})

const containerEl = ref(null)
const contentEl = ref(null)
const scrolling = ref(false)
let intersectionObserver = null
let resizeObserver = null

const checkOverflow = async () => {
  if (!containerEl.value || !contentEl.value) return

  // Reset scrolling to measure a single item's width.
  scrolling.value = false
  await nextTick()

  if (!props.activated) {
    return
  }

  const containerWidth = containerEl.value.offsetWidth
  if (!containerWidth) {
    return
  }

  const contentWidth = contentEl.value.scrollWidth

  if (contentWidth > containerWidth) {
    scrolling.value = true
    await nextTick() // Wait for the second span to render.

    // The animation scrolls by 50% of the total width.
    // This distance is equivalent to the width of the first span (including its padding).
    const scrollDistance = contentEl.value.firstElementChild.offsetWidth
    const animationDuration = scrollDistance / props.speed
    contentEl.value.style.setProperty('--duration', `${animationDuration}s`)
  }
}

const handleResize = () => {
  checkOverflow()
}

onMounted(() => {
  intersectionObserver = new IntersectionObserver(
    (entries) => {
      if (entries[0].isIntersecting) {
        checkOverflow()
      }
    },
    { threshold: 0.01 }
  )

  if (containerEl.value) {
    intersectionObserver.observe(containerEl.value)
  }

  if (typeof ResizeObserver !== 'undefined') {
    resizeObserver = new ResizeObserver(() => {
      checkOverflow()
    })
    if (containerEl.value) resizeObserver.observe(containerEl.value)
    if (contentEl.value) resizeObserver.observe(contentEl.value)
  }

  window.addEventListener('resize', handleResize)
  checkOverflow()
})

onUnmounted(() => {
  if (intersectionObserver) {
    intersectionObserver.disconnect()
    intersectionObserver = null
  }
  if (resizeObserver) {
    resizeObserver.disconnect()
    resizeObserver = null
  }
  window.removeEventListener('resize', handleResize)
})

watch(
  () => props.text,
  () => {
    checkOverflow()
  }
)

watch(
  () => props.activated,
  () => {
    checkOverflow()
  }
)
</script>

<style scoped>
.marquee-container {
  overflow: hidden;
  width: 100%;
  white-space: nowrap;
}

.marquee-content {
  display: inline-flex;
  min-width: 100%;
}

.marquee-content.scrolling {
  animation: marquee var(--duration) linear infinite;
  will-change: transform;
}

.text-item {
  display: inline-block;
  padding-right: 50px; /* Space between repeated text */
  vertical-align: middle;
}

@keyframes marquee {
  0% {
    transform: translateX(0);
  }
  100% {
    transform: translateX(-50%);
  }
}
</style>
