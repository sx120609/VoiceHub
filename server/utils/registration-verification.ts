import { randomInt } from 'crypto'
import { db, systemSettings } from '~/drizzle/db'

type PendingRegistrationCode = {
  userId: number
  code: string
  expiresAt: number
  attempts: number
  lastSentAt: number
}

const CODE_EXPIRES_MS = 10 * 60 * 1000
const RESEND_COOLDOWN_MS = 60 * 1000
const MAX_VERIFY_ATTEMPTS = 5
const pendingRegistrationCodes = new Map<string, PendingRegistrationCode>()

const now = () => Date.now()

const cleanupExpiredCode = (email: string, record?: PendingRegistrationCode | null) => {
  if (!record) {
    return null
  }

  if (record.expiresAt <= now()) {
    pendingRegistrationCodes.delete(email)
    return null
  }

  return record
}

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

export const setPendingRegistrationCode = (email: string, userId: number): PendingRegistrationCode => {
  const record: PendingRegistrationCode = {
    userId,
    code: randomInt(100000, 999999).toString(),
    expiresAt: now() + CODE_EXPIRES_MS,
    attempts: 0,
    lastSentAt: now()
  }

  pendingRegistrationCodes.set(email, record)
  return record
}

export const getPendingRegistrationCode = (email: string): PendingRegistrationCode | null => {
  const record = pendingRegistrationCodes.get(email)
  return cleanupExpiredCode(email, record)
}

export const getRegistrationCodeResendRemainingSeconds = (email: string): number => {
  const record = getPendingRegistrationCode(email)
  if (!record) {
    return 0
  }

  const remaining = Math.ceil((record.lastSentAt + RESEND_COOLDOWN_MS - now()) / 1000)
  return remaining > 0 ? remaining : 0
}

export const verifyPendingRegistrationCode = (
  email: string,
  code: string
): { ok: true; userId: number } | { ok: false; message: string } => {
  const record = getPendingRegistrationCode(email)

  if (!record) {
    return { ok: false, message: '验证码已过期或不存在，请重新发送' }
  }

  if (record.attempts >= MAX_VERIFY_ATTEMPTS) {
    pendingRegistrationCodes.delete(email)
    return { ok: false, message: '验证码尝试次数过多，请重新发送' }
  }

  if (record.code !== code) {
    record.attempts += 1
    pendingRegistrationCodes.set(email, record)
    return {
      ok: false,
      message: `验证码错误，剩余尝试次数：${Math.max(0, MAX_VERIFY_ATTEMPTS - record.attempts)}`
    }
  }

  pendingRegistrationCodes.delete(email)
  return { ok: true, userId: record.userId }
}

export const clearPendingRegistrationCode = (email: string) => {
  pendingRegistrationCodes.delete(email)
}
