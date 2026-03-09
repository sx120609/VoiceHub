import { db, eq, users } from '~/drizzle/db'
import { SmtpService } from '~~/server/services/smtpService'
import { getClientIP } from '~~/server/utils/ip-utils'
import { requireQQEmail } from '~~/server/utils/qq-email'
import {
  getRegistrationCodeResendRemainingSeconds,
  setPendingRegistrationCode
} from '~~/server/utils/registration-verification'

export default defineEventHandler(async (event) => {
  const body = await readBody(event)
  const qqEmail = requireQQEmail(body?.email)

  const userResult = await db
    .select({
      id: users.id,
      name: users.name,
      username: users.username,
      emailVerified: users.emailVerified,
      status: users.status
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

  if (user.emailVerified) {
    return {
      success: true,
      message: '账号已激活，请直接登录'
    }
  }

  const resendRemaining = getRegistrationCodeResendRemainingSeconds(qqEmail)
  if (resendRemaining > 0) {
    throw createError({
      statusCode: 429,
      message: `发送过于频繁，请在 ${resendRemaining} 秒后重试`
    })
  }

  const pendingCode = setPendingRegistrationCode(qqEmail, user.id)
  const smtp = SmtpService.getInstance()
  await smtp.initializeSmtpConfig()

  const sent = await smtp.renderAndSend(
    qqEmail,
    'verification.code',
    {
      name: user.name || user.username,
      email: qqEmail,
      code: pendingCode.code,
      expiresInMinutes: 10,
      action: '账号激活'
    },
    getClientIP(event)
  )

  if (!sent) {
    throw createError({
      statusCode: 500,
      message: '验证码发送失败，请检查SMTP配置或联系管理员手动激活'
    })
  }

  return {
    success: true,
    message: '验证码已发送，请查收邮箱'
  }
})
