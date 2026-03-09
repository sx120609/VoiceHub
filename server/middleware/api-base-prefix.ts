const normalizeBaseURL = (baseURL: string) => {
  const withLeadingSlash = baseURL.startsWith('/') ? baseURL : `/${baseURL}`
  const normalized = withLeadingSlash.replace(/\/{2,}/g, '/')
  return normalized.endsWith('/') ? normalized : `${normalized}/`
}

export default defineEventHandler((event) => {
  const runtimeConfig = useRuntimeConfig(event)
  const appBaseURL = normalizeBaseURL(runtimeConfig.app.baseURL || '/')
  const appBasePrefix = appBaseURL === '/' ? '' : appBaseURL.slice(0, -1)

  if (!appBasePrefix || !event.node.req.url) {
    return
  }

  const apiPrefix = `${appBasePrefix}/api`
  if (event.node.req.url === apiPrefix || event.node.req.url.startsWith(`${apiPrefix}/`)) {
    event.node.req.url = event.node.req.url.slice(appBasePrefix.length) || '/'
  }
})
