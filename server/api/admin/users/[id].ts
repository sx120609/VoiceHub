import { db } from '~/drizzle/db'
import { users } from '~/drizzle/schema'
import { eq } from 'drizzle-orm'
import { requireQQEmail } from '~~/server/utils/qq-email'

export default defineEventHandler(async (event) => {
  // 检查认证和权限
  const user = event.context.user
  if (!user || !['ADMIN', 'SUPER_ADMIN'].includes(user.role)) {
    throw createError({
      statusCode: 403,
      message: '没有权限访问'
    })
  }

  // 安全获取ID参数
  const params = event.context.params || {}
  const id = parseInt(params.id || '')

  if (isNaN(id)) {
    throw createError({
      statusCode: 400,
      message: '无效的用户ID'
    })
  }

  // 处理PUT请求 - 更新用户
  if (event.method === 'PUT') {
    const body = await readBody(event)

    if (!body.name || !body.email) {
      throw createError({
        statusCode: 400,
        message: '姓名和QQ邮箱不能为空'
      })
    }

    try {
      const qqEmail = requireQQEmail(body.email)
      if (body.emailVerified !== undefined && typeof body.emailVerified !== 'boolean') {
        throw createError({
          statusCode: 400,
          message: 'emailVerified 必须是布尔值'
        })
      }

      const existingEmailUserResult = await db
        .select()
        .from(users)
        .where(eq(users.email, qqEmail))
        .limit(1)
      const existingEmailUser = existingEmailUserResult[0]
      if (existingEmailUser && existingEmailUser.id !== id) {
        throw createError({
          statusCode: 400,
          message: '该QQ邮箱已被其他用户绑定'
        })
      }

      const updatedUserResult = await db
        .update(users)
        .set({
          name: body.name,
          email: qqEmail,
          emailVerified: body.emailVerified ?? true,
          role: body.role,
          grade: body.grade,
          class: body.class
        })
        .where(eq(users.id, id))
        .returning({
          id: users.id,
          name: users.name,
          email: users.email,
          emailVerified: users.emailVerified,
          role: users.role,
          createdAt: users.createdAt,
          updatedAt: users.updatedAt,
          grade: users.grade,
          class: users.class,
          username: users.username,
          passwordChangedAt: users.passwordChangedAt
        })
      const updatedUser = updatedUserResult[0]

      return updatedUser
    } catch (error) {
      console.error('更新用户失败:', error)
      if ((error as any)?.statusCode) {
        throw error
      }
      throw createError({
        statusCode: 500,
        message: '更新用户失败'
      })
    }
  }

  // 处理DELETE请求 - 删除用户
  if (event.method === 'DELETE') {
    try {
      const existingUserResult = await db.select().from(users).where(eq(users.id, id)).limit(1)
      if (existingUserResult.length === 0) {
        throw createError({
          statusCode: 404,
          message: '用户不存在'
        })
      }
      const existingUser = existingUserResult[0]

      // 1. 禁止删除 ID 为 1 的用户 (系统初始超级管理员)
      if (existingUser.id === 1) {
        throw createError({
          statusCode: 403,
          message: '无法删除系统初始超级管理员'
        })
      }

      // 2. 确保不能删除自己
      if (String(existingUser.id) === String(user.id)) {
        throw createError({
          statusCode: 400,
          message: '不能删除当前登录的用户'
        })
      }

      // 3. 越级删除保护
      if (existingUser.role === 'SUPER_ADMIN' && user.role !== 'SUPER_ADMIN') {
        throw createError({
          statusCode: 403,
          message: '权限不足：普通管理员无法删除超级管理员'
        })
      }

      await db.delete(users).where(eq(users.id, id))

      return { success: true }
    } catch (error) {
      console.error('删除用户失败:', error)
      if ((error as any)?.statusCode) {
        throw error
      }
      throw createError({
        statusCode: 500,
        message: '删除用户失败'
      })
    }
  }

  throw createError({
    statusCode: 405,
    message: '不支持的请求方法'
  })
})
