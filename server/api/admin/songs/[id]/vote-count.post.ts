import { createError, defineEventHandler, getRouterParam, readBody } from 'h3'
import { asc, count, eq, inArray } from 'drizzle-orm'
import { db } from '~/drizzle/db'
import { songs, users, votes } from '~/drizzle/schema'
import { cacheService } from '~~/server/services/cacheService'

const normalizeSongId = (rawSongId: unknown): number | null => {
  const songId = Number(rawSongId)
  if (!Number.isInteger(songId) || songId <= 0) {
    return null
  }
  return songId
}

const normalizeTargetCount = (rawTargetCount: unknown): number | null => {
  const targetCount = Number(rawTargetCount)
  if (!Number.isInteger(targetCount) || targetCount < 0) {
    return null
  }
  return targetCount
}

export default defineEventHandler(async (event) => {
  const user = event.context.user
  if (!user || !['SONG_ADMIN', 'ADMIN', 'SUPER_ADMIN'].includes(user.role)) {
    throw createError({
      statusCode: 403,
      message: '没有权限访问'
    })
  }

  const songId = normalizeSongId(getRouterParam(event, 'id'))
  if (!songId) {
    throw createError({
      statusCode: 400,
      message: '无效的歌曲ID'
    })
  }

  const body = await readBody(event)
  const targetCount = normalizeTargetCount(body?.targetCount)

  if (targetCount === null) {
    throw createError({
      statusCode: 400,
      message: '目标票数必须是非负整数'
    })
  }

  try {
    const result = await db.transaction(async (tx) => {
      const songRows = await tx
        .select({
          id: songs.id,
          title: songs.title,
          requesterId: songs.requesterId
        })
        .from(songs)
        .where(eq(songs.id, songId))
        .limit(1)

      const song = songRows[0]
      if (!song) {
        throw createError({
          statusCode: 404,
          message: '歌曲不存在'
        })
      }

      const existingVotes = await tx
        .select({
          id: votes.id,
          userId: votes.userId,
          createdAt: votes.createdAt
        })
        .from(votes)
        .where(eq(votes.songId, songId))
        .orderBy(asc(votes.createdAt), asc(votes.id))

      const currentCount = existingVotes.length

      if (targetCount === currentCount) {
        return {
          songId,
          songTitle: song.title,
          previousCount: currentCount,
          totalVotes: currentCount,
          changed: false
        }
      }

      if (targetCount < currentCount) {
        const removeCount = currentCount - targetCount
        const voteIdsToDelete = existingVotes.slice(currentCount - removeCount).map((vote) => vote.id)

        if (voteIdsToDelete.length > 0) {
          await tx.delete(votes).where(inArray(votes.id, voteIdsToDelete))
        }
      } else {
        const needToAdd = targetCount - currentCount
        const votedUserIdSet = new Set(existingVotes.map((vote) => vote.userId))

        const userRows = await tx
          .select({ id: users.id })
          .from(users)

        const availableUserIds = userRows
          .map((row) => row.id)
          .filter((userId) => userId !== song.requesterId && !votedUserIdSet.has(userId))

        if (availableUserIds.length < needToAdd) {
          throw createError({
            statusCode: 400,
            message: `可用于补票的用户不足：当前最多可调整到 ${currentCount + availableUserIds.length} 票`
          })
        }

        const newVoteRows = availableUserIds.slice(0, needToAdd).map((userId) => ({
          songId,
          userId
        }))

        if (newVoteRows.length > 0) {
          await tx.insert(votes).values(newVoteRows)
        }
      }

      const latestCountRows = await tx
        .select({ count: count() })
        .from(votes)
        .where(eq(votes.songId, songId))

      const latestCount = latestCountRows[0]?.count || 0

      return {
        songId,
        songTitle: song.title,
        previousCount: currentCount,
        totalVotes: latestCount,
        changed: true
      }
    })

    try {
      await cacheService.clearSongsCache()
      await cacheService.clearSchedulesCache()
      await cacheService.clearStatsCache()
    } catch (cacheError) {
      console.error('[Admin Vote Count] 清理缓存失败:', cacheError)
    }

    return {
      success: true,
      message: result.changed ? '投票人数已更新' : '投票人数未变化',
      data: result
    }
  } catch (error: unknown) {
    const normalizedError = error as { statusCode?: number; code?: string }

    if (normalizedError?.statusCode) {
      throw error
    }

    if (normalizedError?.code === '23505') {
      throw createError({
        statusCode: 409,
        message: '投票数据已发生变化，请刷新后重试'
      })
    }

    console.error('[Admin Vote Count] 更新投票人数失败:', error)
    throw createError({
      statusCode: 500,
      message: '更新投票人数失败，请稍后重试'
    })
  }
})
