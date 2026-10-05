import { useState } from 'react';
import { QueryClientProvider } from '@tanstack/react-query';
import { BrowserRouter, MemoryRouter, useRoutes } from 'react-router-dom';
import { createQueryClient } from '@/config/queryClient';
import { AuthProvider } from '@/hooks/useAuth';
import { I18nProvider } from '@/i18n';
import { routes } from './routes';

function AppRoutes() {
  return useRoutes(routes);
}

interface Props {
  /** Tests pass initial entries to use an in-memory router. */
  initialEntries?: string[];
  queryClient?: ReturnType<typeof createQueryClient>;
}

export function App({ initialEntries, queryClient }: Props) {
  const [client] = useState(() => queryClient ?? createQueryClient());
  const Router = initialEntries ? MemoryRouter : BrowserRouter;
  return (
    <I18nProvider>
      <QueryClientProvider client={client}>
        <Router {...(initialEntries ? { initialEntries } : {})}>
          <AuthProvider>
            <AppRoutes />
          </AuthProvider>
        </Router>
      </QueryClientProvider>
    </I18nProvider>
  );
}
