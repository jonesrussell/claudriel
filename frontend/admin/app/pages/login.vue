<script setup lang="ts">
definePageMeta({ layout: false })

const route = useRoute()
const { checkAuth, isAuthenticated, loginUrl } = useAuth()

onMounted(async () => {
  await checkAuth()

  if (isAuthenticated.value) {
    await navigateTo('/')
    return
  }

  const redirect = typeof route.query.redirect === 'string' && route.query.redirect !== ''
    ? route.query.redirect
    : '/admin'

  await navigateTo(loginUrl(redirect), { external: true })
})
</script>

<template>
  <div class="login-page">
    <div class="login-form">
      <h1>Redirecting to Claudriel sign-in</h1>
    </div>
  </div>
</template>

<style scoped>
.login-page {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  background: #f5f5f5;
}

.login-form {
  display: flex;
  flex-direction: column;
  width: 100%;
  max-width: 360px;
  padding: 2rem;
  background: #fff;
  border-radius: 8px;
  box-shadow: 0 2px 12px rgba(0, 0, 0, 0.1);
}

.login-form h1 {
  margin: 0 0 0.5rem;
  font-size: 1.5rem;
}

</style>
