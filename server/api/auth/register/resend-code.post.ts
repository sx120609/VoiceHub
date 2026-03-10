import { db, eq, users } from '~/drizzle/db'
import { SmtpService } from '~~/server/services/smtpService'
import { getClientIP } from '~~/server/utils/ip-utils'
import { requireQQEmailOrNumber } from '~~/server/utils/qq-email'
import {
  createRegistrationActivationToken,
  getRegistrationActivationExpiresDays
} from '~~/server/utils/registration-verification'
import { buildPublicAppUrl } from '~~/server/utils/public-url'

const buildActivationUrl = (event: any, token: string) => {
  return buildPublicAppUrl(event, `/api/auth/register/activate?token=${encodeURIComponent(token)}`)
}

export default defineEventHandler(async (event) => {
  const body = await readBody(event)
  const qqEmail = requireQQEmailOrNumber(body?.email ?? body?.qqNumber ?? body?.qq)

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

  const activationToken = createRegistrationActivationToken(qqEmail, user.id)
  const activationUrl = buildActivationUrl(event, activationToken)
  const expiresInDays = getRegistrationActivationExpiresDays()
  const smtp = SmtpService.getInstance()
  await smtp.initializeSmtpConfig()

  const sent = await smtp.renderAndSend(
    qqEmail,
    'verification.link',
    {
      name: user.name || user.username,
      email: qqEmail,
      action: '账号激活',
      actionUrl: activationUrl,
      activationUrl,
      expiresInDays
    },
    getClientIP(event)
  )

  if (!sent) {
    throw createError({
      statusCode: 500,
      message: '激活链接发送失败，请检查SMTP配置或联系管理员手动激活'
    })
  }

  return {
    success: true,
    message: `激活链接已发送，请查收邮箱（${expiresInDays}天内有效）`,
    resendCooldownSeconds: 0
  }
})
