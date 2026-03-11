const CHUNK_RELOAD_GUARD_KEY = 'voicehub:chunk-reload-guard'
const CHUNK_RELOAD_GUARD_TTL_MS = 5 * 60 * 1000

const isChunkLoadError = (value: unknown): boolean => {
  const message =
    typeof value === 'string'
      ? value
      : typeof value === 'object' && value && 'message' in value
        ? String((value as { message?: unknown }).message || '')
        : ''

  if (!message) return false

  return (
    message.includes('Failed to fetch dynamically imported module') ||
    message.includes('Importing a module script failed') ||
    message.includes('Loading chunk') ||
    message.includes('ChunkLoadError')
  )
}

const shouldSkipReload = (): boolean => {
  try {
    const raw = sessionStorage.getItem(CHUNK_RELOAD_GUARD_KEY)
    if (!raw) return false

    const record = JSON.parse(raw) as { path?: string; time?: number }
    if (!record?.path || typeof record.time !== 'number') return false
    if (Date.now() - record.time > CHUNK_RELOAD_GUARD_TTL_MS) return false

    return record.path === window.location.pathname
  } catch {
    return false
  }
}

const markReloadAttempt = () => {
  try {
    sessionStorage.setItem(
      CHUNK_RELOAD_GUARD_KEY,
      JSON.stringify({ path: window.location.pathname, time: Date.now() })
    )
  } catch {
    // Ignore storage failures in private mode or strict browsers.
  }
}

const reloadForChunkRecovery = () => {
  if (shouldSkipReload()) return

  markReloadAttempt()

  const url = new URL(window.location.href)
  url.searchParams.set('_chunk_reload', String(Date.now()))
  window.location.replace(url.toString())
}

export default defineNuxtPlugin(() => {
  const onError = (event: ErrorEvent) => {
    if (isChunkLoadError(event.error || event.message)) {
      reloadForChunkRecovery()
    }
  }

  const onUnhandledRejection = (event: PromiseRejectionEvent) => {
    if (isChunkLoadError(event.reason)) {
      event.preventDefault()
      reloadForChunkRecovery()
    }
  }

  window.addEventListener('error', onError)
  window.addEventListener('unhandledrejection', onUnhandledRejection)
})
