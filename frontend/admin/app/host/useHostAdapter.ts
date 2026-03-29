import type { HostAdapter } from '~/host/hostAdapter'
import { claudrielHostAdapter } from '~/host/claudrielAdapter'
import { claudrielAdminReturnUrl, claudrielPhpLoginUrlWithOrigin } from '~/utils/claudrielAuthUrls'

export function useHostAdapter(): HostAdapter {
  const config = useRuntimeConfig()
  const phpOrigin = () => (config.public.phpOrigin as string) || ''

  return {
    ...claudrielHostAdapter,
    loginUrl(path: string = '/admin'): string {
      if (path.startsWith('http://') || path.startsWith('https://')) {
        return claudrielPhpLoginUrlWithOrigin(phpOrigin(), path)
      }
      const normalized = path.startsWith('/') ? path : `/${path}`
      return claudrielPhpLoginUrlWithOrigin(phpOrigin(), claudrielAdminReturnUrl(normalized))
    },
  }
}
