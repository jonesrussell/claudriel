import { defaultClaudrielPhpOrigin } from '../../devPorts'

/**
 * Split dev: Nuxt + PHP ports are locked in `devPorts.ts`. Session + GraphQL live on PHP; Nitro proxies those paths.
 * Production: same host — login stays a relative `/login?redirect=…` path-only redirect.
 */

export function claudrielPhpOrigin(): string {
  const env = import.meta.env.NUXT_PUBLIC_PHP_ORIGIN
  if (typeof env === 'string' && env.trim() !== '') {
    return env.replace(/\/$/, '')
  }
  if (import.meta.dev) {
    return defaultClaudrielPhpOrigin()
  }

  return ''
}

/**
 * After login, PHP redirects here. In dev with split servers, this must be an absolute admin URL
 * on the Nuxt origin so the browser returns to the SPA; path-only redirects stay on PHP.
 */
export function claudrielAdminReturnUrl(internalPath: string): string {
  const path = internalPath.startsWith('/') ? internalPath : `/${internalPath}`
  if (import.meta.dev && typeof window !== 'undefined') {
    return `${window.location.origin}${path}`
  }

  return path
}

/**
 * Build PHP login URL using an explicit origin (e.g. from useRuntimeConfig().public.phpOrigin).
 * Prefer this for redirects so dev matches nuxt.config/runtimeConfig even when import.meta.env is stale.
 */
export function claudrielPhpLoginUrlWithOrigin(phpBase: string, redirectAfterLogin: string): string {
  const php = phpBase.replace(/\/$/, '')
  const qs = `/login?redirect=${encodeURIComponent(redirectAfterLogin)}`
  return php !== '' ? `${php}${qs}` : qs
}

export function claudrielPhpLoginUrl(redirectAfterLogin: string): string {
  return claudrielPhpLoginUrlWithOrigin(claudrielPhpOrigin(), redirectAfterLogin)
}
