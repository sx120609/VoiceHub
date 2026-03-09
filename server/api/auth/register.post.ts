import bcrypt from 'bcrypt'
import { db, eq, users } from '~/drizzle/db'
import { JWTEnhanced } from '~~/server/utils/jwt-enhanced'
import { requireQQEmailOrNumber } from '~~/server/utils/qq-email'
import { getBeijingTime } from '~/utils/timeUtils'
import { getClientIP } from '~~/server/utils/ip-utils'
import { SmtpService } from '~~/server/services/smtpService'
import { resolveQQDisplayProfile } from '~~/server/utils/qq-profile'
import {
  extractQQNumberFromEmail,
  isRegistrationEmailVerificationEnabled,
  setPendingRegistrationCode
} from '~~/server/utils/registration-verification'

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
    const existingUserByEmail = await db
      .select({
        id: users.id,
        emailVerified: users.emailVerified
      })
      .from(users)
      .where(eq(users.email, qqEmail))
      .limit(1)

    if (existingUserByEmail[0]) {
      throw createError({
        statusCode: 400,
        message: existingUserByEmail[0].emailVerified
          ? '该QQ邮箱已被绑定'
          : '该QQ邮箱已注册但尚未激活，请先完成邮箱验证'
      })
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

    const requireEmailVerification = await isRegistrationEmailVerificationEnabled()
    const hashedPassword = await bcrypt.hash(password, 10)
    const now = getBeijingTime()
    const clientIp = getClientIP(event)

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
      const pendingCode = setPendingRegistrationCode(qqEmail, newUser.id)
      let verificationSent = false

      try {
        const smtp = SmtpService.getInstance()
        await smtp.initializeSmtpConfig()
        verificationSent = await smtp.renderAndSend(
          qqEmail,
          'verification.code',
          {
            name: newUser.name || newUser.username,
            email: qqEmail,
            code: pendingCode.code,
            expiresInMinutes: 10,
            action: '账号激活'
          },
          clientIp
        )
      } catch (mailError) {
        console.error('发送注册验证码失败:', mailError)
      }

      return {
        success: true,
        requiresEmailVerification: true,
        verificationSent,
        email: qqEmail,
        message: verificationSent
          ? '注册成功，请输入邮箱验证码完成激活'
          : '注册成功，但验证码发送失败，请重发验证码或联系管理员手动激活'
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
