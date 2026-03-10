import { createError, defineEventHandler, getQuery } from 'h3'
import { desc, eq } from 'drizzle-orm'
import { db } from '~/drizzle/db'
import { songComments, users } from '~/drizzle/schema'

const isCommentsTableMissing = (error: any) => {
  return (
    error?.code === '42P01' ||
    error?.cause?.code === '42P01' ||
    String(error?.message || '').includes('song_comments')
  )
}

export default defineEventHandler(async (event) => {
  const query = getQuery(event)
  const songId = Number(query.songId)

  if (!songId || Number.isNaN(songId) || songId <= 0) {
    throw createError({
      statusCode: 400,
      message: '无效的歌曲ID'
    })
  }

  try {
    const rows = await db
      .select({
        id: songComments.id,
        content: songComments.content,
        createdAt: songComments.createdAt,
        updatedAt: songComments.updatedAt,
        user: {
          id: users.id,
          username: users.username,
          name: users.name
        }
      })
      .from(songComments)
      .leftJoin(users, eq(songComments.userId, users.id))
      .where(eq(songComments.songId, songId))
      .orderBy(desc(songComments.createdAt))

    const comments = rows.map((row) => {
      const displayName = row.user?.name || row.user?.username || `用户${row.user?.id || ''}`
      return {
        id: row.id,
        content: row.content,
        createdAt: row.createdAt,
        updatedAt: row.updatedAt,
        userId: row.user?.id || null,
        userDisplayName: displayName
      }
    })

    return {
      success: true,
      data: {
        comments,
        total: comments.length
      }
    }
  } catch (error: any) {
    if (isCommentsTableMissing(error)) {
      return {
        success: true,
        data: {
          comments: [],
          total: 0,
          migrationRequired: true
        }
      }
    }

    console.error('[Songs Comments] 获取评论失败:', error)
    throw createError({
      statusCode: 500,
      message: '获取评论失败，请稍后重试'
    })
  }
})
