import bcrypt from 'bcrypt'
import { db, eq, users } from '~/drizzle/db'
import { JWTEnhanced } from '~~/server/utils/jwt-enhanced'
import { requireQQEmailOrNumber } from '~~/server/utils/qq-email'
import { getBeijingTime } from '~/utils/timeUtils'
import { getClientIP } from '~~/server/utils/ip-utils'
import { SmtpService } from '~~/server/services/smtpService'
import { resolveQQDisplayProfile } from '~~/server/utils/qq-profile'
import {
  createRegistrationActivationToken,
  extractQQNumberFromEmail,
  getRegistrationActivationExpiresDays,
  isRegistrationEmailVerificationEnabled,
} from '~~/server/utils/registration-verification'

const buildActivationUrl = (event: any, token: string) => {
  const requestUrl = getRequestURL(event)
  const runtimeConfig = useRuntimeConfig()
  const rawBaseURL = (runtimeConfig.app?.baseURL || '/').toString()
  const normalizedBaseURL = rawBaseURL === '/' ? '' : rawBaseURL.replace(/\/$/, '')
  return `${requestUrl.origin}${normalizedBaseURL}/api/auth/register/activate?token=${encodeURIComponent(token)}`
}

export default defineEventHandler(async (event) => {
  const body = await readBody(event)
  const password = (body?.password || '').toString()
  const qqEmail = requireQQEmailOrNumber(body?.email ?? body?.qqNumber ?? body?.qq)
  const username = extractQQNumberFromEmail(qqEmail)

  if (!password) {
    throw createError({
      statusCode: 400,
      message: 'QQ邮箱和密码不能为空'
    })
  }

  if (password.length < 6) {
    throw createError({
      statusCode: 400,
      message: '密码长度不能少于 6 位'
    })
  }

  try {
    const requireEmailVerification = await isRegistrationEmailVerificationEnabled()
    const clientIp = getClientIP(event)
    const activationExpiresDays = getRegistrationActivationExpiresDays()

    const sendActivationLink = async (email: string, userId: number, name: string, username: string) => {
      const token = createRegistrationActivationToken(email, userId)
      const activationUrl = buildActivationUrl(event, token)
      const smtp = SmtpService.getInstance()
      await smtp.initializeSmtpConfig()

      return await smtp.renderAndSend(
        email,
        'verification.link',
        {
          name: name || username,
          email,
          action: '账号激活',
          actionUrl: activationUrl,
          activationUrl,
          expiresInDays: activationExpiresDays
        },
        clientIp
      )
    }

    const existingUserByEmail = await db
      .select({
        id: users.id,
        name: users.name,
        username: users.username,
        status: users.status,
        emailVerified: users.emailVerified
      })
      .from(users)
      .where(eq(users.email, qqEmail))
      .limit(1)

    if (existingUserByEmail[0]) {
      const existingUser = existingUserByEmail[0]

      if (existingUser.emailVerified) {
        throw createError({
          statusCode: 400,
          message: '该QQ邮箱已被绑定'
        })
      }

      if (existingUser.status !== 'active') {
        throw createError({
          statusCode: 403,
          message: '账号当前不可用，请联系管理员处理'
        })
      }

      if (!requireEmailVerification) {
        await db.update(users).set({ emailVerified: true }).where(eq(users.id, existingUser.id))
        throw createError({
          statusCode: 400,
          message: '该QQ邮箱已注册，请直接登录'
        })
      }

      let verificationSent = false
      try {
        verificationSent = await sendActivationLink(
          qqEmail,
          existingUser.id,
          existingUser.name || '',
          existingUser.username || username
        )
      } catch (mailError) {
        console.error('重发注册激活链接失败:', mailError)
      }

      return {
        success: true,
        requiresEmailVerification: true,
        verificationSent,
        email: qqEmail,
        message: verificationSent
          ? `该QQ邮箱已注册但尚未激活，激活链接已发送（${activationExpiresDays}天内有效）`
          : '该QQ邮箱已注册但尚未激活，发送激活链接失败，请稍后重试'
      }
    }

    const existingUserByUsername = await db
      .select({ id: users.id })
      .from(users)
      .where(eq(users.username, username))
      .limit(1)

    if (existingUserByUsername[0]) {
      throw createError({
        statusCode: 400,
        message: '该QQ号已被占用，请联系管理员处理'
      })
    }

    const hashedPassword = await bcrypt.hash(password, 10)
    const now = getBeijingTime()

    const newUserResult = await db
      .insert(users)
      .values({
        name: username,
        username,
        password: hashedPassword,
        role: 'USER',
        status: 'active',
        email: qqEmail,
        emailVerified: !requireEmailVerification,
        passwordChangedAt: now,
        forcePasswordChange: false,
        ...(!requireEmailVerification
          ? {
              lastLogin: now,
              lastLoginIp: clientIp
            }
          : {})
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

    if (requireEmailVerification) {
      let verificationSent = false

      try {
        verificationSent = await sendActivationLink(
          qqEmail,
          newUser.id,
          newUser.name || '',
          newUser.username || username
        )
      } catch (mailError) {
        console.error('发送注册激活链接失败:', mailError)
      }

      return {
        success: true,
        requiresEmailVerification: true,
        verificationSent,
        email: qqEmail,
        message: verificationSent
          ? `注册成功，请点击邮箱中的激活链接完成激活（${activationExpiresDays}天内有效）`
          : '注册成功，但激活链接发送失败，请重发激活链接或联系管理员手动激活'
      }
    }

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
    const qqProfile = await resolveQQDisplayProfile(newUser.username, qqEmail)

    return {
      success: true,
      requiresEmailVerification: false,
      message: '注册成功',
      user: {
        ...newUser,
        name: qqProfile?.name || newUser.name,
        avatar: qqProfile?.avatar || null,
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
