import { createError } from 'h3'

// QQ 邮箱账号通常为 5-11 位数字，首位不能为 0
const QQ_EMAIL_REGEX = /^[1-9]\d{4,10}@qq\.com$/i

export const normalizeEmail = (email: string): string => email.trim().toLowerCase()

export const isValidQQEmail = (email: string): boolean => QQ_EMAIL_REGEX.test(email)

export const requireQQEmail = (email: unknown, fieldName = 'QQ邮箱'): string => {
  if (typeof email !== 'string' || !email.trim()) {
    throw createError({
      statusCode: 400,
      message: `${fieldName}不能为空`
    })
  }

  const normalized = normalizeEmail(email)
  if (!isValidQQEmail(normalized)) {
    throw createError({
      statusCode: 400,
      message: `请输入有效的${fieldName}（仅支持 @qq.com）`
    })
  }

  return normalized
}
