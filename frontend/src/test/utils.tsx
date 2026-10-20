import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { vi } from 'vitest';
import { AuthProvider } from '../app/auth/AuthContext';
import { AppRoutes } from '../app/routes';
import { tokenStore } from '../shared/api/client';
import type { CurrentUser, Scope } from '../shared/api/types';

export interface MockRoute {
  method?: string;
  path: string;
  status?: number;
  body?: unknown | ((request: { query: URLSearchParams; body: any }) => unknown);
}

export interface RecordedCall {
  method: string;
  path: string;
  query: URLSearchParams;
  body: any;
  headers: Record<string, string>;
}

/** Multipart bodies are recorded as plain objects: repeated "name[]" keys become arrays, files become their file name. */
function formDataToObject(form: FormData): Record<string, unknown> {
  const result: Record<string, unknown> = {};
  form.forEach((value, key) => {
    const plain = value instanceof File ? `file:${value.name}` : value;
    if (key.endsWith('[]')) {
      const name = key.slice(0, -2);
      result[name] = [...((result[name] as string[] | undefined) ?? []), plain];
    } else {
      result[key] = plain;
    }
  });

  return result;
}

/** Replaces fetch with an in-memory API. Later routes win, so a test can override a default. */
export function mockApi(routes: MockRoute[]): RecordedCall[] {
  const calls: RecordedCall[] = [];

  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = new URL(String(input), 'http://localhost');
      const path = url.pathname.replace('/api/v1', '');
      const method = init?.method ?? 'GET';
      const body = init?.body instanceof FormData ? formDataToObject(init.body) : init?.body ? JSON.parse(String(init.body)) : undefined;
      calls.push({ method, path, query: url.searchParams, body, headers: (init?.headers ?? {}) as Record<string, string> });

      const route = [...routes].reverse().find((candidate) => (candidate.method ?? 'GET') === method && candidate.path === path);
      if (!route) {
        return new Response(JSON.stringify({ message: `unmocked ${method} ${path}` }), { status: 500 });
      }

      const payload = typeof route.body === 'function' ? route.body({ query: url.searchParams, body }) : route.body;

      return new Response(payload === undefined ? null : JSON.stringify(payload), {
        status: route.status ?? 200,
        headers: { 'Content-Type': 'application/json' },
      });
    }),
  );

  return calls;
}

export function page<T>(items: T[], overrides: Partial<{ current_page: number; last_page: number; total: number }> = {}) {
  return {
    data: items,
    meta: { current_page: 1, last_page: 1, per_page: 15, total: items.length, ...overrides },
  };
}

export function fakeUser(permissions: Record<string, Scope>, overrides: Partial<CurrentUser> = {}): CurrentUser {
  return {
    id: 1,
    name: 'سارة',
    username: 'sara',
    active: true,
    must_change_password: false,
    role: { id: 3, code: 'branch_staff', name_ar: 'موظف الفرع' },
    branch: { id: 1, name: 'فرع القاهرة' },
    agent: null,
    permissions,
    ...overrides,
  };
}

interface RenderOptions {
  /** Signed-in user, or null for an anonymous visitor. */
  user: CurrentUser | null;
  routes?: MockRoute[];
}

/** Renders the whole app at a URL, with a mocked API and (optionally) a restored session. */
export function renderApp(route: string, { user, routes = [] }: RenderOptions) {
  tokenStore.clear();
  if (user) {
    tokenStore.set('test-token');
  }
  const calls = mockApi([{ path: '/auth/me', body: { user } }, ...routes]);
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[route]}>
        <AuthProvider>
          <AppRoutes />
        </AuthProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  );

  return calls;
}
