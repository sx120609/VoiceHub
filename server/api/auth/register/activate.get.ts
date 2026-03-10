import { getQuery, sendRedirect } from 'h3'
import { and, eq } from 'drizzle-orm'
import { db, users } from '~/drizzle/db'
import { verifyRegistrationActivationToken } from '~~/server/utils/registration-verification'
import { buildPublicAppUrl } from '~~/server/utils/public-url'

const buildLoginRedirectUrl = (event: any, activation: string) => {
  return buildPublicAppUrl(event, `/login?activation=${encodeURIComponent(activation)}`)
}

export default defineEventHandler(async (event) => {
  const query = getQuery(event)
  const token = typeof query.token === 'string' ? query.token.trim() : ''

  if (!token) {
    return sendRedirect(event, buildLoginRedirectUrl(event, 'invalid'), 302)
  }

  const verifyResult = verifyRegistrationActivationToken(token)
  if (!verifyResult.ok) {
    const status = verifyResult.message.includes('过期') ? 'expired' : 'invalid'
    return sendRedirect(event, buildLoginRedirectUrl(event, status), 302)
  }

  const { userId, email } = verifyResult.payload

  const userResult = await db
    .select({
      id: users.id,
      status: users.status,
      emailVerified: users.emailVerified
    })
    .from(users)
    .where(and(eq(users.id, userId), eq(users.email, email)))
    .limit(1)

  const user = userResult[0]
  if (!user) {
    return sendRedirect(event, buildLoginRedirectUrl(event, 'invalid'), 302)
  }

  if (user.status !== 'active') {
    return sendRedirect(event, buildLoginRedirectUrl(event, 'blocked'), 302)
  }

  if (!user.emailVerified) {
    await db.update(users).set({ emailVerified: true }).where(eq(users.id, user.id))
    return sendRedirect(event, buildLoginRedirectUrl(event, 'success'), 302)
  }

  return sendRedirect(event, buildLoginRedirectUrl(event, 'already'), 302)
})
