import { createError, defineEventHandler, readMultipartFormData, type H3Event } from 'h3'
import { promises as fs } from 'fs'
import { randomUUID } from 'crypto'
import path from 'path'
import { eq } from 'drizzle-orm'
import { db } from '~/drizzle/db'
import { users } from '~/drizzle/schema'
import { cacheService } from '~~/server/services/cacheService'
import { cache } from '~~/server/utils/cache-helpers'

const AVATAR_PUBLIC_PREFIX = '/uploads/avatars'
const MAX_AVATAR_SIZE = 2 * 1024 * 1024
const ALLOWED_MIME_TYPES = new Set(['image/jpeg', 'image/png', 'image/webp'])
const MIME_EXTENSION_MAP: Record<string, string> = {
  'image/jpeg': 'jpg',
  'image/png': 'png',
  'image/webp': 'webp'
}

const getPublicRootCandidates = (): string[] => {
  const root = process.cwd()
  const devFirstCandidates = [path.join(root, 'public'), path.join(root, '.output', 'public')]
  const prodFirstCandidates = [path.join(root, '.output', 'public'), path.join(root, 'public')]
  return process.env.NODE_ENV === 'production' ? prodFirstCandidates : devFirstCandidates
}

const resolveWritablePublicRoot = async (): Promise<string> => {
  const candidates = getPublicRootCandidates()

  for (const candidate of candidates) {
    try {
      await fs.access(candidate)
      return candidate
    } catch {
      // ignore
    }
  }

  return candidates[0]
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

const withAppBasePath = (event: H3Event, pathValue: string): string => {
  const runtimeConfig = useRuntimeConfig(event)
  const rawBase = String(runtimeConfig.app?.baseURL || '/').trim()
  const normalizedBase = rawBase === '/' ? '' : `/${rawBase.replace(/^\/+|\/+$/g, '')}`
  const normalizedPath = pathValue.startsWith('/') ? pathValue : `/${pathValue}`
  return `${normalizedBase}${normalizedPath}`
}

const resolveErrorStatusCode = (error: unknown): number => {
  if (
    typeof error === 'object' &&
    error &&
    'statusCode' in error &&
    typeof (error as { statusCode?: unknown }).statusCode === 'number'
  ) {
    return (error as { statusCode: number }).statusCode
  }
  return 500
}

const resolveErrorMessage = (error: unknown): string => {
  if (
    typeof error === 'object' &&
    error &&
    'message' in error &&
    typeof (error as { message?: unknown }).message === 'string'
  ) {
    return (error as { message: string }).message
  }
  return '头像上传失败'
}

export default defineEventHandler(async (event) => {
  const authUser = event.context.user

  if (!authUser) {
    throw createError({
      statusCode: 401,
      message: '需要登录后才能上传头像'
    })
  }

  const formData = await readMultipartFormData(event)
  if (!formData || formData.length === 0) {
    throw createError({
      statusCode: 400,
      message: '未检测到上传文件'
    })
  }

  const avatarFile = formData.find((item) => item.name === 'avatar' && item.filename)
  if (!avatarFile || !avatarFile.data) {
    throw createError({
      statusCode: 400,
      message: '请上传头像文件'
    })
  }

  const mimeType = avatarFile.type || ''
  if (!ALLOWED_MIME_TYPES.has(mimeType)) {
    throw createError({
      statusCode: 400,
      message: '仅支持 JPG / PNG / WEBP 格式图片'
    })
  }

  if (avatarFile.data.length === 0) {
    throw createError({
      statusCode: 400,
      message: '上传文件为空'
    })
  }

  if (avatarFile.data.length > MAX_AVATAR_SIZE) {
    throw createError({
      statusCode: 400,
      message: '头像大小不能超过 2MB'
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

  const extension = MIME_EXTENSION_MAP[mimeType]
  const fileName = `${authUser.id}-${Date.now()}-${randomUUID().slice(0, 8)}.${extension}`
  const publicRoot = await resolveWritablePublicRoot()
  const avatarUploadDir = path.join(publicRoot, 'uploads', 'avatars')
  const fileAbsolutePath = path.join(avatarUploadDir, fileName)
  const publicAvatarPath = withAppBasePath(event, `${AVATAR_PUBLIC_PREFIX}/${fileName}`)

  let dbUpdated = false

  try {
    await fs.mkdir(avatarUploadDir, { recursive: true })
    await fs.writeFile(fileAbsolutePath, avatarFile.data)

    const updatedUserResult = await db
      .update(users)
      .set({
        avatar: publicAvatarPath,
        updatedAt: new Date()
      })
      .where(eq(users.id, authUser.id))
      .returning({
        id: users.id,
        avatar: users.avatar
      })

    const updatedUser = updatedUserResult[0]
    if (!updatedUser) {
      throw createError({
        statusCode: 404,
        message: '用户不存在'
      })
    }

    dbUpdated = true

    const oldAvatarPaths = resolveStoredAvatarPaths(currentUser.avatar)
    for (const oldAvatarPath of oldAvatarPaths) {
      if (oldAvatarPath !== fileAbsolutePath) {
        await fs.unlink(oldAvatarPath).catch(() => {})
      }
    }

    try {
      await cache.delete(`auth:user:${authUser.id}`)
      await cache.deletePattern('songs:*')
      await cache.deletePattern('public_schedules:*')
      await cacheService.clearSongsCache()
      await cacheService.clearSchedulesCache()
      console.log(`[Cache] 用户头像更新后缓存已清理: ${authUser.id}`)
    } catch (cacheError) {
      console.warn('[Cache] 用户头像更新后清理缓存失败:', cacheError)
    }

    return {
      success: true,
      data: {
        avatar: updatedUser.avatar
      },
      message: '头像上传成功'
    }
  } catch (error: unknown) {
    if (!dbUpdated) {
      await fs.unlink(fileAbsolutePath).catch(() => {})
    }

    throw createError({
      statusCode: resolveErrorStatusCode(error),
      message: resolveErrorMessage(error)
    })
  }
})
