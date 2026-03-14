export default defineNuxtRouteMiddleware(async (to) => {
  // Auth check runs client-side only. The PHP backend is the authoritative
  // security layer; the Nuxt middleware is a UX redirect guard.
  if (!import.meta.client) {
    return
  }

  const { isAuthenticated, checkAuth, loginUrl } = useAuth()

  if (to.path === '/login') {
    await checkAuth()
    if (isAuthenticated.value) {
      return navigateTo('/')
    }

    return navigateTo(loginUrl('/admin'), { external: true })
  }

  await checkAuth()

  if (!isAuthenticated.value) {
    const target = to.fullPath.startsWith('/admin') ? to.fullPath : `/admin${to.fullPath}`
    return navigateTo(loginUrl(target), { external: true })
  }
})
