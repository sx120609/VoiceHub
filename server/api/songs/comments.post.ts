import { createError, defineEventHandler, readBody } from 'h3'
import { count, eq } from 'drizzle-orm'
import { db } from '~/drizzle/db'
import { songComments, songs, users } from '~/drizzle/schema'

const isCommentsTableMissing = (error: any) => {
  return (
    error?.code === '42P01' ||
    error?.cause?.code === '42P01' ||
    String(error?.message || '').includes('song_comments')
  )
}

export default defineEventHandler(async (event) => {
  const user = event.context.user
  if (!user) {
    throw createError({
      statusCode: 401,
      message: '请先登录后再评论'
    })
  }

  const body = await readBody(event)
  const songId = Number(body?.songId)
  const content = String(body?.content || '').trim()

  if (!songId || Number.isNaN(songId) || songId <= 0) {
    throw createError({
      statusCode: 400,
      message: '无效的歌曲ID'
    })
  }

  if (!content) {
    throw createError({
      statusCode: 400,
      message: '评论内容不能为空'
    })
  }

  if (content.length > 300) {
    throw createError({
      statusCode: 400,
      message: '评论内容不能超过300字'
    })
  }

  try {
    const songRows = await db.select({ id: songs.id }).from(songs).where(eq(songs.id, songId)).limit(1)
    if (songRows.length === 0) {
      throw createError({
        statusCode: 404,
        message: '歌曲不存在'
      })
    }

    const inserted = await db
      .insert(songComments)
      .values({
        songId,
        userId: user.id,
        content
      })
      .returning({
        id: songComments.id,
        content: songComments.content,
        createdAt: songComments.createdAt,
        updatedAt: songComments.updatedAt,
        userId: songComments.userId
      })

    const newComment = inserted[0]

    const countResult = await db
      .select({ count: count(songComments.id) })
      .from(songComments)
      .where(eq(songComments.songId, songId))

    const userRow = await db
      .select({
        id: users.id,
        username: users.username,
        name: users.name
      })
      .from(users)
      .where(eq(users.id, user.id))
      .limit(1)

    const currentUser = userRow[0]
    const userDisplayName = currentUser?.name || currentUser?.username || `用户${user.id}`

    return {
      success: true,
      message: '评论发布成功',
      data: {
        comment: {
          id: newComment.id,
          content: newComment.content,
          createdAt: newComment.createdAt,
          updatedAt: newComment.updatedAt,
          userId: newComment.userId,
          userDisplayName
        },
        commentCount: countResult[0]?.count || 0
      }
    }
  } catch (error: any) {
    if (error?.statusCode) {
      throw error
    }

    if (isCommentsTableMissing(error)) {
      throw createError({
        statusCode: 503,
        message: '评论功能尚未初始化，请先执行数据库迁移'
      })
    }

    console.error('[Songs Comments] 发表评论失败:', error)
    throw createError({
      statusCode: 500,
      message: '发表评论失败，请稍后重试'
    })
  }
})
