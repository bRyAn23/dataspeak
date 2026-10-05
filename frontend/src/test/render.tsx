import { render } from '@testing-library/react';
import { App } from '@/app/App';
import { createQueryClient } from '@/config/queryClient';

export function renderApp(initialEntries: string[] = ['/login']) {
  const queryClient = createQueryClient();
  queryClient.setDefaultOptions({
    queries: { ...queryClient.getDefaultOptions().queries, retry: false },
  });
  return render(<App initialEntries={initialEntries} queryClient={queryClient} />);
}
