import { createError, defineEventHandler } from 'h3'
import { promises as fs } from 'fs'
import path from 'path'
import { eq } from 'drizzle-orm'
import { db } from '~/drizzle/db'
import { users } from '~/drizzle/schema'
import { cacheService } from '~~/server/services/cacheService'
import { cache } from '~~/server/utils/cache-helpers'

const getPublicRootCandidates = (): string[] => {
  const root = process.cwd()
  const devFirstCandidates = [path.join(root, 'public'), path.join(root, '.output', 'public')]
  const prodFirstCandidates = [path.join(root, '.output', 'public'), path.join(root, 'public')]
  return process.env.NODE_ENV === 'production' ? prodFirstCandidates : devFirstCandidates
}

const resolveStoredAvatarPaths = (avatar?: string | null): string[] => {
  if (typeof avatar !== 'string') {
    return []
  }

  const normalized = avatar.trim()
  if (!normalized) {
    return []
  }

  const marker = '/uploads/avatars/'
  const markerIndex = normalized.indexOf(marker)
  if (markerIndex === -1) {
    return []
  }

  const relativePath = normalized.slice(markerIndex + 1)
  if (!relativePath || relativePath.includes('..')) {
    return []
  }

  return getPublicRootCandidates().map((root) => path.join(root, relativePath))
}

export default defineEventHandler(async (event) => {
  const authUser = event.context.user

  if (!authUser) {
    throw createError({
      statusCode: 401,
      message: '需要登录后才能移除头像'
    })
  }

  const currentUserResult = await db
    .select({
      id: users.id,
      avatar: users.avatar
    })
    .from(users)
    .where(eq(users.id, authUser.id))
    .limit(1)

  const currentUser = currentUserResult[0]
  if (!currentUser) {
    throw createError({
      statusCode: 404,
      message: '用户不存在'
    })
  }

  const oldAvatarPaths = resolveStoredAvatarPaths(currentUser.avatar)

  await db
    .update(users)
    .set({
      avatar: null,
      updatedAt: new Date()
    })
    .where(eq(users.id, authUser.id))

  for (const oldAvatarPath of oldAvatarPaths) {
    await fs.unlink(oldAvatarPath).catch(() => {})
  }

  try {
    await cache.delete(`auth:user:${authUser.id}`)
    await cache.deletePattern('songs:*')
    await cache.deletePattern('public_schedules:*')
    await cacheService.clearSongsCache()
    await cacheService.clearSchedulesCache()
    console.log(`[Cache] 用户头像移除后缓存已清理: ${authUser.id}`)
  } catch (cacheError) {
    console.warn('[Cache] 用户头像移除后清理缓存失败:', cacheError)
  }

  return {
    success: true,
    data: {
      avatar: null
    },
    message: currentUser.avatar ? '头像已移除' : '当前未设置自定义头像'
  }
})
