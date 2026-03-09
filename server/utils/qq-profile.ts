import iconv from 'iconv-lite'

type QQDisplayProfile = {
  name?: string
  avatar?: string
}

type QQProfileCacheItem = {
  expiresAt: number
  nickname: string | null
}

const QQ_NUMBER_REGEX = /^[1-9]\d{4,10}$/
const QQ_PROFILE_TTL_MS = 6 * 60 * 60 * 1000
const QQ_PROFILE_TIMEOUT_MS = 2500
const qqProfileCache = new Map<string, QQProfileCacheItem>()

const normalizeQQNumber = (value?: string | null): string | null => {
  if (!value) {
    return null
  }
  const normalized = value.trim()
  return QQ_NUMBER_REGEX.test(normalized) ? normalized : null
}

const extractQQNumberFromEmail = (email?: string | null): string | null => {
  if (!email) {
    return null
  }

  const normalized = email.trim().toLowerCase()
  if (!normalized.endsWith('@qq.com')) {
    return null
  }

  return normalizeQQNumber(normalized.slice(0, -'@qq.com'.length))
}

const getQQNumberFromAccount = (username?: string | null, email?: string | null): string | null => {
  return normalizeQQNumber(username) || extractQQNumberFromEmail(email)
}

const getCachedNickname = (qqNumber: string): string | null | undefined => {
  const cached = qqProfileCache.get(qqNumber)
  if (!cached) {
    return undefined
  }

  if (cached.expiresAt <= Date.now()) {
    qqProfileCache.delete(qqNumber)
    return undefined
  }

  return cached.nickname
}

const setCachedNickname = (qqNumber: string, nickname: string | null) => {
  qqProfileCache.set(qqNumber, {
    nickname,
    expiresAt: Date.now() + QQ_PROFILE_TTL_MS
  })
}

const sanitizeQQNickname = (value: unknown): string | null => {
  if (typeof value !== 'string') {
    return null
  }

  const trimmed = value.trim()
  if (!trimmed) {
    return null
  }

  // 避免把乱码昵称写到页面上
  if (/�|锟斤拷|\\uFFFD/i.test(trimmed)) {
    return null
  }

  return trimmed
}

const decodePortraitResponse = (buffer: Buffer): string => {
  try {
    const gbText = iconv.decode(buffer, 'gb18030')
    if (/portraitCallBack\(/i.test(gbText)) {
      return gbText
    }
  } catch {
    // ignore and fallback to utf-8
  }
  return buffer.toString('utf8')
}

const fetchQQNickname = async (qqNumber: string): Promise<string | null> => {
  const url = `https://users.qzone.qq.com/fcg-bin/cgi_get_portrait.fcg?uins=${qqNumber}`

  try {
    const response = await fetch(url, {
      method: 'GET',
      signal: AbortSignal.timeout(QQ_PROFILE_TIMEOUT_MS),
      headers: {
        'user-agent': 'Mozilla/5.0 VoiceHub/1.0',
        accept: 'text/plain,*/*'
      }
    })

    if (!response.ok) {
      return null
    }

    const buffer = Buffer.from(await response.arrayBuffer())
    const responseText = decodePortraitResponse(buffer)

    const callbackMatch = responseText.match(/portraitCallBack\(([\s\S]+)\)\s*;?\s*$/i)
    if (!callbackMatch) {
      return null
    }

    const payload = JSON.parse(callbackMatch[1])
    if (!payload || typeof payload !== 'object') {
      return null
    }

    const item = payload[qqNumber]
    if (!Array.isArray(item)) {
      return null
    }

    return sanitizeQQNickname(item[6])
  } catch (error) {
    console.warn(`[QQProfile] Failed to fetch nickname for ${qqNumber}:`, error)
    return null
  }
}

export const getQQAvatarUrl = (qqNumber: string): string =>
  `https://q1.qlogo.cn/g?b=qq&nk=${qqNumber}&s=100`

export const resolveQQDisplayProfile = async (
  username?: string | null,
  email?: string | null
): Promise<QQDisplayProfile | null> => {
  const qqNumber = getQQNumberFromAccount(username, email)
  if (!qqNumber) {
    return null
  }

  const cachedNickname = getCachedNickname(qqNumber)
  if (cachedNickname !== undefined) {
    return {
      name: cachedNickname || undefined,
      avatar: getQQAvatarUrl(qqNumber)
    }
  }

  const nickname = await fetchQQNickname(qqNumber)
  setCachedNickname(qqNumber, nickname)

  return {
    name: nickname || undefined,
    avatar: getQQAvatarUrl(qqNumber)
  }
}
