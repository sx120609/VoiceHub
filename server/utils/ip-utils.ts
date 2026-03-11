import type { H3Event } from 'h3'
import { getHeaders } from 'h3'
import { isIP } from 'node:net'

const TRUSTED_PROXY_IPS = new Set(
  (process.env.TRUSTED_PROXY_IPS || '')
    .split(',')
    .map((v) => normalizeIP(v))
    .filter((v): v is string => Boolean(v))
)

function normalizeIP(raw: string | undefined | null): string | null {
  if (!raw) return null
  let value = raw.trim()
  if (!value) return null

  // RFC7239 Forwarded: for=1.2.3.4;proto=https
  if (value.toLowerCase().startsWith('for=')) {
    value = value.slice(4).trim()
  }

  // 去掉参数部分（;proto=...）
  if (value.includes(';')) {
    value = value.split(';')[0].trim()
  }

  // 去掉包裹引号
  value = value.replace(/^"+|"+$/g, '')

  // IPv6 带中括号: [2001:db8::1]:443
  if (value.startsWith('[')) {
    const end = value.indexOf(']')
    if (end > 1) {
      value = value.slice(1, end)
    }
  } else {
    // IPv4:port
    const colonCount = (value.match(/:/g) || []).length
    if (colonCount === 1 && value.includes('.')) {
      value = value.split(':')[0].trim()
    }
  }

  // IPv4-mapped IPv6
  value = value.replace(/^::ffff:/i, '')

  if (!isIP(value)) return null
  return value
}

function parseIPList(raw: string | undefined): string[] {
  if (!raw) return []
  return raw
    .split(',')
    .map((part) => normalizeIP(part))
    .filter((ip): ip is string => Boolean(ip))
}

function parseForwarded(raw: string | undefined): string[] {
  if (!raw) return []
  const ips: string[] = []
  const entries = raw.split(',')
  for (const entry of entries) {
    const segments = entry.split(';')
    for (const seg of segments) {
      const ip = normalizeIP(seg)
      if (ip) {
        ips.push(ip)
      }
    }
  }
  return ips
}

function isPrivateIPv4(ip: string): boolean {
  const parts = ip.split('.').map((n) => Number(n))
  if (parts.length !== 4 || parts.some((n) => Number.isNaN(n))) return false
  const [a, b] = parts
  if (a === 10) return true
  if (a === 127) return true
  if (a === 192 && b === 168) return true
  if (a === 172 && b >= 16 && b <= 31) return true
  if (a === 169 && b === 254) return true
  if (a === 100 && b >= 64 && b <= 127) return true
  return false
}

function isPrivateIP(ip: string): boolean {
  if (isIP(ip) === 4) return isPrivateIPv4(ip)
  const lower = ip.toLowerCase()
  if (lower === '::1') return true
  if (lower.startsWith('fc') || lower.startsWith('fd')) return true // fc00::/7
  if (lower.startsWith('fe8') || lower.startsWith('fe9') || lower.startsWith('fea') || lower.startsWith('feb')) {
    return true // fe80::/10
  }
  return false
}

/**
 * 获取客户端真实IP（兼容多层反代）
 * 默认自动识别：
 * 1) 优先使用 X-Forwarded-For / Forwarded 链路中的首个公网IP（通常是客户端）
 * 2) 若无公网IP则使用链路首个有效IP
 * 3) 最后回退到 X-Real-IP / socket.remoteAddress
 *
 * TRUSTED_PROXY_IPS 为可选增强配置，不填也可正常工作。
 */
export function getClientIP(event: H3Event): string {
  const headers = getHeaders(event)

  const socketIP = normalizeIP(event.node.req.socket?.remoteAddress)
  const xRealIP = normalizeIP((headers['x-real-ip'] as string | undefined) || '')

  const xForwardedFor = parseIPList(headers['x-forwarded-for'] as string | undefined)
  const forwarded = parseForwarded(headers.forwarded as string | undefined)

  const headerChain = [...xForwardedFor, ...forwarded]

  if (headerChain.length > 0) {
    // 可选：剔除已知代理IP（若未配置则不影响自动识别）
    const filteredChain = headerChain.filter((ip) => !TRUSTED_PROXY_IPS.has(ip))

    // 1) 优先使用链路首个公网IP（最常见即真实客户端IP）
    for (const ip of filteredChain) {
      if (!isPrivateIP(ip)) {
        return ip
      }
    }

    // 2) 无公网时，使用链路首个有效IP
    if (filteredChain[0]) {
      return filteredChain[0]
    }

    // 3) filtered 为空时退回原始链路首个
    if (headerChain[0]) {
      return headerChain[0]
    }
  }

  // 4) 兜底 x-real-ip / socket
  if (xRealIP) return xRealIP
  if (socketIP) return socketIP

  return 'unknown'
}

/**
 * 格式化IP地址用于邮件显示
 * @param ip IP地址
 * @returns 格式化后的IP地址字符串
 */
export function formatIPForEmail(ip: string): string {
  if (!ip || ip === 'unknown') {
    return '未知'
  }

  // 如果是本地/内网IP，显示为本地网络
  if (isPrivateIP(ip)) {
    return `${ip} (本地网络)`
  }

  return ip
}
