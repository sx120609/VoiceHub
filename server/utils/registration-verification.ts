import jwt from 'jsonwebtoken'
import { db, systemSettings } from '~/drizzle/db'

const REGISTRATION_ACTIVATION_EXPIRES_DAYS = 3
const REGISTRATION_ACTIVATION_EXPIRES_SECONDS = REGISTRATION_ACTIVATION_EXPIRES_DAYS * 24 * 60 * 60

export const extractQQNumberFromEmail = (email: string): string => email.split('@')[0]

export const isRegistrationEmailVerificationEnabled = async (): Promise<boolean> => {
  const settingsResult = await db
    .select({
      enableRegistrationEmailVerification: systemSettings.enableRegistrationEmailVerification
    })
    .from(systemSettings)
    .limit(1)

  return !!settingsResult[0]?.enableRegistrationEmailVerification
}

const getJwtSecret = (): string => {
  if (!process.env.JWT_SECRET) {
    throw new Error('JWT_SECRET is required for registration activation links')
  }
  return process.env.JWT_SECRET
}

export const getRegistrationActivationExpiresDays = (): number => {
  return REGISTRATION_ACTIVATION_EXPIRES_DAYS
}

export const createRegistrationActivationToken = (email: string, userId: number): string => {
  return jwt.sign(
    {
      type: 'register_activation',
      userId,
      email: email.toLowerCase()
    },
    getJwtSecret(),
    {
      expiresIn: REGISTRATION_ACTIVATION_EXPIRES_SECONDS
    }
  )
}

export const verifyRegistrationActivationToken = (
  token: string
): { ok: true; payload: { userId: number; email: string } } | { ok: false; message: string } => {
  try {
    const decoded = jwt.verify(token, getJwtSecret()) as {
      type?: string
      userId?: number
      email?: string
    }

    if (decoded?.type !== 'register_activation') {
      return { ok: false, message: '激活链接无效，请重新发送' }
    }

    const userId = Number(decoded.userId)
    const email = typeof decoded.email === 'string' ? decoded.email.toLowerCase() : ''
    if (!userId || !email) {
      return { ok: false, message: '激活链接无效，请重新发送' }
    }

    return {
      ok: true,
      payload: { userId, email }
    }
  } catch (error: any) {
    if (error?.name === 'TokenExpiredError') {
      return { ok: false, message: '激活链接已过期，请重新发送' }
    }
    return { ok: false, message: '激活链接无效，请重新发送' }
  }
}
