const NETEASE_DIRECT_BASE_URLS = ['https://ncmapi.zcy.life:443', 'https://api.voicehub.lao-shui.top:443']
const NETEASE_PROXY_TIMEOUT = 10000

const normalizeEndpoint = (endpoint) => (endpoint.startsWith('/') ? endpoint : `/${endpoint}`)

const buildRequestUrl = (baseUrl, path, queryString) => {
  const normalizedBase = baseUrl.replace(/\/+$/, '')
  return queryString ? `${normalizedBase}${path}?${queryString}` : `${normalizedBase}${path}`
}

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

  for (const baseUrl of NETEASE_DIRECT_BASE_URLS) {
    try {
      raw = await $fetch(buildRequestUrl(baseUrl, path, query.toString()), {
        retry: 0,
        timeout: NETEASE_PROXY_TIMEOUT
      })
      break
    } catch (error) {
      lastError = error
    }
  }

  if (!raw) {
    throw lastError || new Error('网易云请求失败')
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
