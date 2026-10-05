// FE-SVC-001 support: typed access to build-time configuration.
// The frontend knows exactly one upstream: the Laravel application API.

const raw = import.meta.env.VITE_API_BASE_URL as string | undefined;

export const env = {
  apiBaseUrl: (raw && raw.trim()) || 'http://localhost:8000/api/v1',
  isDev: import.meta.env.DEV,
} as const;
