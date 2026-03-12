const NETEASE_PROXY_SOURCES = ['netease-backup-2', 'netease-backup-1']
const NETEASE_PROXY_TIMEOUT = 10000

const normalizeEndpoint = (endpoint) => (endpoint.startsWith('/') ? endpoint : `/${endpoint}`)

export function normalizeNeteaseResponse(data) {
  if (!data || typeof data !== 'object') {
    return {
      code: undefined,
      message: '',
      body: undefined
    }
  }

  // 检查是否已经是标准格式 { code: 200, data: ... }
  if (data.code === 200 && data.data) {
    return {
      code: data.code,
      message: data.message || '',
      body: data.data // 将 data 字段映射为 body
    }
  }

  const body =
    Object.prototype.hasOwnProperty.call(data, 'body') && data.body && typeof data.body === 'object'
      ? data.body
      : data
  const code = typeof body.code === 'number' ? body.code : undefined
  const message = typeof body.message === 'string' ? body.message : ''
  return {
    code,
    message,
    body
  }
}

export async function fetchNetease(endpoint, params = {}, cookie) {
  const path = normalizeEndpoint(endpoint)
  const query = new URLSearchParams()
  for (const key in params) {
    if (params[key] !== undefined && params[key] !== null) {
      query.append(key, params[key])
    }
  }
  if (cookie) {
    query.append('cookie', cookie)
  }
  query.append('timestamp', Date.now().toString())

  let raw
  let lastError

  for (const source of NETEASE_PROXY_SOURCES) {
    try {
      raw = await $fetch('/api/music/proxy', {
        retry: 0,
        params: {
          source,
          path,
          q: query.toString(),
          responseType: 'json',
          timeout: NETEASE_PROXY_TIMEOUT
        }
      })
      break
    } catch (error) {
      lastError = error
    }
  }

  if (!raw) {
    throw lastError || new Error('网易云代理请求失败')
  }

  return normalizeNeteaseResponse(raw)
}

export async function getUserPlaylists(uid, cookie) {
  return fetchNetease('/user/playlist', { uid, limit: 100 }, cookie)
}

export async function createPlaylist(name, isPrivate, cookie) {
  const params = { name }
  if (isPrivate) {
    params.privacy = 10
  }
  return fetchNetease('/playlist/create', params, cookie)
}

export async function deletePlaylist(id, cookie) {
  return fetchNetease('/playlist/delete', { id }, cookie)
}

export async function addSongsToPlaylist(pid, tracks, cookie) {
  return fetchNetease('/playlist/tracks', { op: 'add', pid, tracks: tracks.join(',') }, cookie)
}

export async function getPlaylistTracks(id, limit = 1000, offset = 0, cookie) {
  return fetchNetease('/playlist/track/all', { id, limit, offset }, cookie)
}

export async function getRecentSongs(limit = 100, cookie) {
  return fetchNetease('/record/recent/song', { limit }, cookie)
}

export async function getLoginStatus(cookie) {
  return fetchNetease('/login/status', {}, cookie)
}
