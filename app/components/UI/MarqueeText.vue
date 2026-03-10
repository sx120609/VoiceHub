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
let rafId = 0

const queueOverflowCheck = () => {
  if (rafId) {
    cancelAnimationFrame(rafId)
  }
  rafId = requestAnimationFrame(() => {
    rafId = 0
    checkOverflow()
  })
}

const checkOverflow = async () => {
  if (!containerEl.value || !contentEl.value) return

  const containerWidth = containerEl.value.offsetWidth
  if (!containerWidth) {
    return
  }

  const firstTextItem = contentEl.value.firstElementChild
  if (!firstTextItem) {
    return
  }

  const singleTextWidth = firstTextItem.offsetWidth
  const shouldScroll = props.activated && singleTextWidth > containerWidth

  if (scrolling.value !== shouldScroll) {
    scrolling.value = shouldScroll
    await nextTick()
  }

  if (shouldScroll) {
    const scrollDistance = firstTextItem.offsetWidth
    const animationDuration = Math.max(scrollDistance / props.speed, 4)
    contentEl.value.style.setProperty('--duration', `${animationDuration}s`)
  }
}

const handleResize = () => {
  queueOverflowCheck()
}

onMounted(() => {
  intersectionObserver = new IntersectionObserver(
    (entries) => {
      if (entries[0].isIntersecting) {
        queueOverflowCheck()
      }
    },
    { threshold: 0.01 }
  )

  if (containerEl.value) {
    intersectionObserver.observe(containerEl.value)
  }

  if (typeof ResizeObserver !== 'undefined') {
    resizeObserver = new ResizeObserver(() => {
      queueOverflowCheck()
    })
    if (containerEl.value) resizeObserver.observe(containerEl.value)
  }

  window.addEventListener('resize', handleResize)
  queueOverflowCheck()
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
  if (rafId) {
    cancelAnimationFrame(rafId)
    rafId = 0
  }
  window.removeEventListener('resize', handleResize)
})

watch(
  () => props.text,
  () => {
    queueOverflowCheck()
  }
)

watch(
  () => props.activated,
  () => {
    queueOverflowCheck()
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
