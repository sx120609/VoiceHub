import bcrypt from 'bcrypt'
import { db, eq, users } from '~/drizzle/db'
import { JWTEnhanced } from '~~/server/utils/jwt-enhanced'
import { requireQQEmail } from '~~/server/utils/qq-email'
import { getBeijingTime } from '~/utils/timeUtils'
import { getClientIP } from '~~/server/utils/ip-utils'

export default defineEventHandler(async (event) => {
  const body = await readBody(event)
  const name = (body?.name || '').toString().trim()
  const username = (body?.username || '').toString().trim()
  const password = (body?.password || '').toString()
  const qqEmail = requireQQEmail(body?.email)

  if (!name || !username || !password) {
    throw createError({
      statusCode: 400,
      message: '姓名、用户名、QQ邮箱和密码不能为空'
    })
  }

  if (username.length < 3 || username.length > 32) {
    throw createError({
      statusCode: 400,
      message: '用户名长度需在 3 到 32 个字符之间'
    })
  }

  if (password.length < 6) {
    throw createError({
      statusCode: 400,
      message: '密码长度不能少于 6 位'
    })
  }

  try {
    const existingUserByUsername = await db.select().from(users).where(eq(users.username, username)).limit(1)
    if (existingUserByUsername[0]) {
      throw createError({
        statusCode: 400,
        message: '用户名已存在'
      })
    }

    const existingUserByEmail = await db.select().from(users).where(eq(users.email, qqEmail)).limit(1)
    if (existingUserByEmail[0]) {
      throw createError({
        statusCode: 400,
        message: '该QQ邮箱已被绑定'
      })
    }

    const hashedPassword = await bcrypt.hash(password, 10)
    const now = getBeijingTime()
    const clientIp = getClientIP(event)

    const newUserResult = await db
      .insert(users)
      .values({
        name,
        username,
        password: hashedPassword,
        role: 'USER',
        status: 'active',
        email: qqEmail,
        emailVerified: true,
        passwordChangedAt: now,
        forcePasswordChange: false,
        lastLogin: now,
        lastLoginIp: clientIp
      })
      .returning({
        id: users.id,
        username: users.username,
        name: users.name,
        grade: users.grade,
        class: users.class,
        role: users.role
      })

    const newUser = newUserResult[0]

    const token = JWTEnhanced.generateToken(newUser.id, newUser.role)
    const isSecure =
      getRequestURL(event).protocol === 'https:' ||
      getRequestHeader(event, 'x-forwarded-proto') === 'https'

    setCookie(event, 'auth-token', token, {
      httpOnly: true,
      secure: isSecure,
      sameSite: 'lax',
      maxAge: 60 * 60 * 24 * 7,
      path: '/'
    })

    return {
      success: true,
      user: {
        ...newUser,
        needsPasswordChange: false
      }
    }
  } catch (error: any) {
    console.error('用户注册失败:', error)
    if (error?.statusCode) {
      throw error
    }
    throw createError({
      statusCode: 500,
      message: '注册失败，请稍后重试'
    })
  }
})
