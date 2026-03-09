const normalizeBaseURL = (baseURL: string) => {
  const withLeadingSlash = baseURL.startsWith('/') ? baseURL : `/${baseURL}`
  const normalized = withLeadingSlash.replace(/\/{2,}/g, '/')
  return normalized.endsWith('/') ? normalized : `${normalized}/`
}

export default defineEventHandler((event) => {
  const runtimeConfig = useRuntimeConfig(event)
  const appBaseURL = normalizeBaseURL(runtimeConfig.app.baseURL || '/')
  const appBasePrefix = appBaseURL === '/' ? '' : appBaseURL.slice(0, -1)
  const requestUrl = event.node.req.url

  if (!appBasePrefix || !requestUrl) {
    return
  }

  // Compatibility mode for reverse proxies that strip the /rareapp prefix.
  if (requestUrl === appBasePrefix || requestUrl.startsWith(`${appBasePrefix}/`)) {
    return
  }

  const normalizedRequestUrl = requestUrl.startsWith('/') ? requestUrl : `/${requestUrl}`
  event.node.req.url = `${appBasePrefix}${normalizedRequestUrl}`
})
