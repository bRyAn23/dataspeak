import { QueryClient } from '@tanstack/react-query';

// No persisted cache: query data (which may include business labels) stays in memory only.
export function createQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        retry: (failureCount, error) => {
          const status = (error as { status?: number }).status ?? 0;
          if (
            status === 401 ||
            status === 403 ||
            status === 404 ||
            status === 409 ||
            status === 422
          )
            return false;
          return failureCount < 2;
        },
        staleTime: 10_000,
        refetchOnWindowFocus: false,
      },
      mutations: { retry: false },
    },
  });
}
