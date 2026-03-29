/**
 * Canonical local dev ports for Claudriel split-stack (PHP + Nuxt admin).
 * Avoids 8081/3000/3333 collisions with other stacks (Minoo, Mercure, etc.).
 * Change here and align: composer.json serve:php, config/waaseyaa.php cors_origins, .env.example OAuth URIs.
 */
export const CLAUDRIEL_DEV_PHP_PORT = 37840 as const
export const CLAUDRIEL_DEV_ADMIN_PORT = 37841 as const

export function defaultClaudrielPhpOrigin(): string {
  return `http://localhost:${CLAUDRIEL_DEV_PHP_PORT}`
}
