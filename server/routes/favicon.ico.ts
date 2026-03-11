import { db } from '~/drizzle/db'
import { systemSettings } from '~/drizzle/schema'

const normalizeAppBase = (baseURL: string) => {
  const withLeadingSlash = baseURL.startsWith('/') ? baseURL : `/${baseURL}`
  const normalized = withLeadingSlash.replace(/\/{2,}/g, '/')
  if (normalized === '/') return ''
  return normalized.endsWith('/') ? normalized.slice(0, -1) : normalized
}

const withBase = (path: string, basePrefix: string) => {
  if (!basePrefix) return path
  if (path.startsWith(`${basePrefix}/`) || path === basePrefix) {
    return path
  }
  return `${basePrefix}${path}`
}

const resolveFaviconTarget = (logoUrl: string, basePrefix: string) => {
  const value = (logoUrl || '').trim()
  const fallback = withBase('/images/logo.png', basePrefix)

  if (!value || value === '/favicon.ico' || value.endsWith('/favicon.ico')) {
    return fallback
  }

  if (value.startsWith('http://') || value.startsWith('https://')) {
    return withBase(`/api/proxy/image?url=${encodeURIComponent(value)}`, basePrefix)
  }

  if (value.startsWith('data:')) {
    return fallback
  }

  if (value.startsWith('/')) {
    return withBase(value, basePrefix)
  }

  return withBase(`/${value.replace(/^\/+/, '')}`, basePrefix)
}

export default defineEventHandler(async (event) => {
  const runtimeConfig = useRuntimeConfig(event)
  const basePrefix = normalizeAppBase((runtimeConfig as any)?.app?.baseURL || '/')

  let logoUrl = ''
  try {
    const settingsResult = await db
      .select({
        siteLogoUrl: systemSettings.siteLogoUrl
      })
      .from(systemSettings)
      .limit(1)
    logoUrl = settingsResult[0]?.siteLogoUrl || ''
  } catch (error) {
    console.warn('[favicon] 获取站点 logo 配置失败，使用默认 logo', error)
  }

  const target = resolveFaviconTarget(logoUrl, basePrefix)
  return sendRedirect(event, target, 302)
})
