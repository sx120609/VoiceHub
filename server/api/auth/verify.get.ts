import jwt from 'jsonwebtoken'
import { db } from '~/drizzle/db'
import { users } from '~/drizzle/schema'
import { executeRedisCommand, isRedisReady } from '../../utils/redis'
import { eq } from 'drizzle-orm'
import { resolveQQDisplayProfile } from '~~/server/utils/qq-profile'
import { normalizeRoleOrDefault } from '~~/server/utils/role'

// 用户认证缓存（永久缓存，登出或权限变更时主动失效）

export default defineEventHandler(async (event) => {
  try {
    const token =
      getCookie(event, 'auth-token') || getHeader(event, 'authorization')?.replace('Bearer ', '')

    if (!token) {
      throw createError({
        statusCode: 401,
        message: '未提供认证令牌'
      })
    }

    // 验证JWT令牌
    const decoded = jwt.verify(token, process.env.JWT_SECRET!) as { userId: number }
    const userId = decoded.userId

    // 优先从Redis缓存获取用户认证状态
    if (isRedisReady()) {
      const cachedUser = await executeRedisCommand(async () => {
        const client = (await import('../../utils/redis')).getRedisClient()
        if (!client) return null

        const cacheKey = `auth:user:${userId}`
        const userData = await client.get(cacheKey)

        if (userData) {
          console.log(`[API] 用户认证缓存命中: ${userId}`)
          return JSON.parse(userData)
        }

        return null
      })

      if (cachedUser) {
        // 为缓存的用户数据添加字段
        const userWithDetails = {
          id: cachedUser.id,
          username: cachedUser.username,
          name: cachedUser.name,
          grade: cachedUser.grade,
          class: cachedUser.class,
          role: normalizeRoleOrDefault(cachedUser.role, 'USER'),
          requirePasswordChange: cachedUser.forcePasswordChange || !cachedUser.passwordChangedAt,
          has2FA: false,
          avatar: cachedUser.identities?.find((id: any) => id.provider === 'github')?.providerUsername
            ? `https://github.com/${cachedUser.identities.find((id: any) => id.provider === 'github').providerUsername}.png`
            : null
        }
        const qqProfile = await resolveQQDisplayProfile(cachedUser.username, cachedUser.email)
        return {
          user: {
            ...userWithDetails,
            name: userWithDetails.name || qqProfile?.name || userWithDetails.username,
            avatar: qqProfile?.avatar || userWithDetails.avatar
          },
          valid: true
        }
      }
    }

    // 缓存未命中或Redis不可用，从数据库获取用户信息
    // 同时查询所有身份绑定信息
    const userResult = await db.query.users.findFirst({
      where: eq(users.id, userId),
      columns: {
        id: true,
        username: true,
        name: true,
        grade: true,
        class: true,
        role: true,
        email: true,
        forcePasswordChange: true,
        passwordChangedAt: true
      },
      with: {
        identities: {
          columns: {
            provider: true,
            providerUsername: true
          }
        }
      }
    })

    const dbUser = userResult || null

    if (!dbUser) {
      throw createError({
        statusCode: 401,
        message: '用户不存在'
      })
    }

    // 构建返回的用户对象，只包含需要的字段
    const githubIdentity = dbUser.identities?.find((id: any) => id.provider === 'github')
    const qqProfile = await resolveQQDisplayProfile(dbUser.username, dbUser.email)
    const user = {
      id: dbUser.id,
      username: dbUser.username,
      name: dbUser.name || qqProfile?.name || dbUser.username,
      grade: dbUser.grade,
      class: dbUser.class,
      role: normalizeRoleOrDefault(dbUser.role, 'USER'),
      requirePasswordChange: dbUser.forcePasswordChange || !dbUser.passwordChangedAt,
      has2FA: false,
      // 动态生成 GitHub 头像 URL
      avatar: qqProfile?.avatar || (githubIdentity?.providerUsername
        ? `https://github.com/${githubIdentity.providerUsername}.png`
        : null)
    }

    // 将用户认证状态缓存到Redis（如果可用）- 永久缓存
    if (isRedisReady()) {
      await executeRedisCommand(async () => {
        const client = (await import('../../utils/redis')).getRedisClient()
        if (!client) return

        const cacheKey = `auth:user:${userId}`
        // 缓存完整的数据库用户信息，用于后续验证
        await client.set(cacheKey, JSON.stringify(dbUser))
        console.log(`[API] 用户认证状态已缓存: ${userId}`)
      })
    }

    return {
      user,
      valid: true
    }
  } catch (error) {
    if (error.name === 'JsonWebTokenError' || error.name === 'TokenExpiredError') {
      throw createError({
        statusCode: 401,
        message: '令牌无效或已过期'
      })
    }

    throw error
  }
})
