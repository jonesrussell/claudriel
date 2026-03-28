import { ref, computed, onMounted, onUnmounted } from 'vue'

export function useClock() {
  const now = ref(new Date())
  let timer: ReturnType<typeof setInterval> | null = null

  const formatted = computed(() =>
    now.value.toLocaleString(undefined, {
      weekday: 'long',
      year: 'numeric',
      month: 'long',
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
      second: '2-digit',
      timeZoneName: 'short',
    }),
  )

  onMounted(() => {
    timer = setInterval(() => {
      now.value = new Date()
    }, 1000)
  })

  onUnmounted(() => {
    if (timer) clearInterval(timer)
  })

  return { formatted }
}
