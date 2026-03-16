type AvatarPriorityInput = {
  customAvatar?: string | null
  qqAvatar?: string | null
  githubUsername?: string | null
}

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
