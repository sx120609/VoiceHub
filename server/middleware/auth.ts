import { JWTEnhanced } from '../utils/jwt-enhanced'
import { db, users } from '~/drizzle/db'
import { eq } from 'drizzle-orm'
import { isUserBlocked, getUserBlockRemainingTime } from '../services/securityService'
import { normalizeRoleOrDefault } from '~~/server/utils/role'
import { clearAuthTokenCookie } from '~~/server/utils/auth-cookie'

const normalizeBaseURL = (baseURL: string) => {
  const withLeadingSlash = baseURL.startsWith('/') ? baseURL : `/${baseURL}`
  const normalized = withLeadingSlash.replace(/\/{2,}/g, '/')
  return normalized.endsWith('/') ? normalized : `${normalized}/`
}

const stripBaseFromPath = (path: string, baseURL: string) => {
  const normalizedBase = normalizeBaseURL(baseURL)
  const basePrefix = normalizedBase === '/' ? '' : normalizedBase.slice(0, -1)

  if (!basePrefix) {
    return path
  }

  if (path === basePrefix) {
    return '/'
  }

  return path.startsWith(`${basePrefix}/`) ? path.slice(basePrefix.length) : path
}

export default defineEventHandler(async (event) => {
  // 清除用户上下文
  if (event.context.user) {
    delete event.context.user
  }

  const url = getRequestURL(event)
  const pathname = url.pathname
  const runtimeConfig = useRuntimeConfig(event)
  const baseURL =
    (runtimeConfig as any)?.app?.baseURL ||
    (runtimeConfig as any)?.public?.appBaseURL ||
    process.env.NUXT_APP_BASE_URL ||
    '/'
  const routePath = stripBaseFromPath(pathname, baseURL)

  // 跳过非API路由
  if (!routePath.startsWith('/api/')) {
    return
  }

  const requestMethod = getMethod(event).toUpperCase()

  // 评论列表和评论数允许公开读取（发表评论仍需登录）
  if (
    requestMethod === 'GET' &&
    (routePath.startsWith('/api/songs/comments/list') ||
      routePath.startsWith('/api/songs/comments/counts'))
  ) {
    return
  }

  // 歌曲列表接口允许匿名访问，但如果携带 token 需要解析用户态（用于返回 voted/replayRequested）
  const isOptionalAuthRoute = requestMethod === 'GET' && routePath === '/api/songs'

  // 公共API路径
  const publicApiPaths = [
    '/api/healthz',
    '/api/auth/login',
    '/api/auth/email-login/',
    '/api/auth/forgot-password/',
    '/api/auth/register',
    '/api/auth/bind', // 账号绑定
    '/api/auth/verify', // verify端点自行处理token验证
    '/api/semesters/current',
    '/api/play-times',
    '/api/schedules/public',
    '/api/songs/count',
    '/api/songs/public',
    '/api/site-config',
    '/api/proxy/', // 代理API路径，用于图片代理等功能
    '/api/bilibili/', // 哔哩哔哩相关API
    '/api/native-api/', // Native Music 集成API
    '/api/system/location', // 系统位置检测API
    '/api/open/', // 开放API路径，由api-auth中间件处理认证
    '/api/auth/webauthn/login', // WebAuthn 登录接口
    '/api/music/state', // 音乐状态同步
    '/api/music/websocket' // WebSocket 连接
  ]

  // 公共路径跳过认证检查
  if (publicApiPaths.some((path) => routePath.startsWith(path))) {
    return
  }

  // 动态判断 OAuth 路径
  // 允许 /api/auth/[provider] 和 /api/auth/[provider]/callback
  // 但排除已知的受保护/特定 Auth 端点
  if (routePath.startsWith('/api/auth/')) {
    const segments = routePath.split('/')
    // segments: ['', 'api', 'auth', 'provider', 'callback'?]
    const provider = segments[3]

    // 已知的受保护或非OAuth的Auth端点
    const nonOAuthEndpoints = [
      'identities',
      'logout',
      'change-password',
      'set-initial-password',
      'unbind',
      // login, bind, verify 已经在 publicApiPaths 中处理，这里列出是为了完整性或防止意外匹配
      'login',
      'bind',
      'verify',
      'email-login',
      'forgot-password',
      'webauthn',
      'register'
    ]

    if (provider && !nonOAuthEndpoints.includes(provider)) {
      // 这是一个 OAuth 提供商路径 (例如 /api/auth/casdoor)
      return
    }
  }

  // 从请求头或cookie获取token
  let token: string | null = null
  const authHeader = getRequestHeader(event, 'authorization')
  if (authHeader && authHeader.startsWith('Bearer ')) {
    token = authHeader.substring(7)
  }

  if (!token) {
    token = getCookie(event, 'auth-token') || null
  }

  // 受保护路由缺少token时返回401错误
  if (!token) {
    if (isOptionalAuthRoute) {
      return
    }
    return sendError(
      event,
      createError({
        statusCode: 401,
        message: '未授权访问：缺少有效的认证信息'
      })
    )
  }

  try {
    // 验证token并自动续期
    const { valid, payload, newToken } = JWTEnhanced.verifyAndRefresh(token)

    if (!valid || !payload) {
      throw new Error('Token无效')
    }

    // 如果生成了新token，更新cookie
    if (newToken) {
      const isSecure =
        getRequestURL(event).protocol === 'https:' ||
        getRequestHeader(event, 'x-forwarded-proto') === 'https'
      setCookie(event, 'auth-token', newToken, {
        httpOnly: true,
        secure: isSecure,
        sameSite: 'lax',
        maxAge: 60 * 60 * 24 * 7, // 7天
        path: '/'
      })
    }

    const decoded = payload
    const userResult = await db
      .select({
        id: users.id,
        username: users.username,
        name: users.name,
        grade: users.grade,
        class: users.class,
        role: users.role,
        passwordChangedAt: users.passwordChangedAt
      })
      .from(users)
      .where(eq(users.id, decoded.userId))
      .limit(1)

    const user = userResult[0] || null

    // 用户不存在时token无效
    if (!user) {
      clearAuthTokenCookie(event)
      if (isOptionalAuthRoute) {
        return
      }
      return sendError(
        event,
        createError({
          statusCode: 401,
          message: '用户不存在，请重新登录'
        })
      )
    }

    // 检查token是否在密码修改之前签发（强制旧token失效）
    if (user.passwordChangedAt && decoded.iat) {
      const passwordChangedTime = Math.floor(new Date(user.passwordChangedAt).getTime() / 1000)
      if (decoded.iat < passwordChangedTime) {
        clearAuthTokenCookie(event)

        if (isOptionalAuthRoute) {
          return
        }

        return sendError(
          event,
          createError({
            statusCode: 401,
            message: '密码已修改，请重新登录',
            data: { invalidToken: true, passwordChanged: true }
          })
        )
      }
    }

    const normalizedUser = {
      ...user,
      role: normalizeRoleOrDefault(user.role, 'USER')
    }
    event.context.user = normalizedUser

    if (isUserBlocked(normalizedUser.id)) {
      delete event.context.user
      const remaining = getUserBlockRemainingTime(normalizedUser.id)
      if (isOptionalAuthRoute) {
        return
      }
      return sendError(
        event,
        createError({
          statusCode: 401,
          message: `账户处于风险控制期，请在 ${remaining} 分钟后重试`
        })
      )
    }

    // 检查管理员专用路由
    if (
      routePath.startsWith('/api/admin') &&
      !['ADMIN', 'SUPER_ADMIN', 'SONG_ADMIN'].includes(normalizedUser.role)
    ) {
      return sendError(
        event,
        createError({
          statusCode: 403,
          message: '需要管理员权限'
        })
      )
    }
  } catch (error: any) {
    // 处理JWT验证错误
    if (isOptionalAuthRoute) {
      return
    }
    return sendError(
      event,
      createError({
        statusCode: 401,
        message: `认证失败: ${error.message}`,
        data: { invalidToken: true }
      })
    )
  }
})
