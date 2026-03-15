import { sql } from 'drizzle-orm'
import { db } from '~/drizzle/db'

type AvatarPriorityInput = {
  customAvatar?: string | null
  qqAvatar?: string | null
  githubUsername?: string | null
}

let avatarColumnState: 'unknown' | 'present' | 'missing' = 'unknown'

const normalizeAvatar = (value: unknown): string | null => {
  if (typeof value !== 'string') return null
  const trimmed = value.trim()
  return trimmed.length > 0 ? trimmed : null
}

const getGithubAvatar = (githubUsername?: string | null): string | null => {
  const normalized = normalizeAvatar(githubUsername)
  return normalized ? `https://github.com/${normalized}.png` : null
}

export const resolvePreferredAvatar = ({
  customAvatar,
  qqAvatar,
  githubUsername
}: AvatarPriorityInput): string | null => {
  return normalizeAvatar(customAvatar) || normalizeAvatar(qqAvatar) || getGithubAvatar(githubUsername)
}

export const readUserCustomAvatar = async (userId: number): Promise<string | null> => {
  if (!Number.isInteger(userId) || userId <= 0 || avatarColumnState === 'missing') {
    return null
  }

  try {
    const result = await db.execute(
      sql`SELECT "avatar" FROM "User" WHERE "id" = ${userId} LIMIT 1`
    )
    avatarColumnState = 'present'
    const row = (result as any)?.rows?.[0]
    return normalizeAvatar(row?.avatar)
  } catch (error: any) {
    const message = String(error?.message || '').toLowerCase()
    if (message.includes('column') && message.includes('avatar')) {
      avatarColumnState = 'missing'
      return null
    }

    return null
  }
}
