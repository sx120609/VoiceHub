import { db, eq, users } from '~/drizzle/db'
import { JWTEnhanced } from '~~/server/utils/jwt-enhanced'
import { requireQQEmailOrNumber } from '~~/server/utils/qq-email'
import { getBeijingTime } from '~/utils/timeUtils'
import { getClientIP } from '~~/server/utils/ip-utils'
import { verifyPendingRegistrationCode } from '~~/server/utils/registration-verification'
import { resolveQQDisplayProfile } from '~~/server/utils/qq-profile'

export default defineEventHandler(async (event) => {
  const body = await readBody(event)
  const qqEmail = requireQQEmailOrNumber(body?.email ?? body?.qqNumber ?? body?.qq)
  const code = (body?.code || '').toString().trim()

  if (!/^\d{6}$/.test(code)) {
    throw createError({
      statusCode: 400,
      message: '请输入 6 位数字验证码'
    })
  }

  const userResult = await db
    .select({
      id: users.id,
      username: users.username,
      name: users.name,
      grade: users.grade,
      class: users.class,
      role: users.role,
      status: users.status,
      emailVerified: users.emailVerified
    })
    .from(users)
    .where(eq(users.email, qqEmail))
    .limit(1)

  const user = userResult[0]
  if (!user) {
    throw createError({
      statusCode: 404,
      message: '账号不存在'
    })
  }

  if (user.status !== 'active') {
    throw createError({
      statusCode: 403,
      message: '账号当前不可用，请联系管理员处理'
    })
  }

  if (!user.emailVerified) {
    const verifyResult = verifyPendingRegistrationCode(qqEmail, code)
    if (!verifyResult.ok) {
      throw createError({
        statusCode: 400,
        message: verifyResult.message
      })
    }

    if (verifyResult.userId !== user.id) {
      throw createError({
        statusCode: 400,
        message: '验证码与账号不匹配，请重新获取'
      })
    }

    await db
      .update(users)
      .set({
        emailVerified: true,
        lastLogin: getBeijingTime(),
        lastLoginIp: getClientIP(event)
      })
      .where(eq(users.id, user.id))
  }

  const token = JWTEnhanced.generateToken(user.id, user.role)
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

  const qqProfile = await resolveQQDisplayProfile(user.username, qqEmail)

  return {
    success: true,
    message: '账号激活成功，已自动登录',
    user: {
      id: user.id,
      username: user.username,
      name: qqProfile?.name || user.name,
      grade: user.grade,
      class: user.class,
      role: user.role,
      avatar: qqProfile?.avatar || null,
      needsPasswordChange: false
    }
  }
})
