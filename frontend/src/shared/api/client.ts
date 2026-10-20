const TOKEN_KEY = 'gomana.token';
const BASE_URL = (import.meta.env.VITE_API_URL ?? '').replace(/\/$/, '');

export const tokenStore = {
  get(): string | null {
    try {
      return window.localStorage.getItem(TOKEN_KEY);
    } catch {
      return null;
    }
  },
  set(token: string): void {
    try {
      window.localStorage.setItem(TOKEN_KEY, token);
    } catch {
      // storage unavailable: the session just will not survive a reload
    }
  },
  clear(): void {
    try {
      window.localStorage.removeItem(TOKEN_KEY);
    } catch {
      // nothing to clear
    }
  },
};

/** A failed API call. `errors` maps a field name to its validation messages (HTTP 422). */
export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly errors: Record<string, string[]> = {},
  ) {
    super(message);
    this.name = 'ApiError';
  }

  /** First message for a field, if the server rejected it. */
  fieldError(field: string): string | undefined {
    return this.errors[field]?.[0];
  }
}

let onUnauthorized: (() => void) | null = null;

/** The auth layer registers how to react when the server says the token is no longer valid. */
export function setUnauthorizedHandler(handler: (() => void) | null): void {
  onUnauthorized = handler;
}

type Query = Record<string, string | number | boolean | null | undefined>;

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  query?: Query;
  body?: unknown;
  signal?: AbortSignal;
  /** Do not treat a 401 as an expired session (used by the login call itself). */
  skipUnauthorizedHandler?: boolean;
}

function buildUrl(path: string, query?: Query): string {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(query ?? {})) {
    if (value !== undefined && value !== null && value !== '') {
      params.set(key, String(value));
    }
  }
  const qs = params.toString();

  return `${BASE_URL}/api/v1${path}${qs ? `?${qs}` : ''}`;
}

export async function api<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  const token = tokenStore.get();
  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }
  // FormData (file uploads) must be sent as-is so the browser sets the multipart boundary itself.
  const isForm = options.body instanceof FormData;
  if (options.body !== undefined && !isForm) {
    headers['Content-Type'] = 'application/json';
  }

  let response: Response;
  try {
    response = await fetch(buildUrl(path, options.query), {
      method: options.method ?? 'GET',
      headers,
      body: options.body === undefined ? undefined : isForm ? (options.body as FormData) : JSON.stringify(options.body),
      signal: options.signal,
    });
  } catch (error) {
    if (error instanceof DOMException && error.name === 'AbortError') {
      throw error;
    }
    throw new ApiError('تعذر الاتصال بالسيرفر، راجع الإنترنت وحاول تاني', 0);
  }

  if (response.status === 204) {
    return undefined as T;
  }

  const payload: unknown = await response.json().catch(() => null);

  if (!response.ok) {
    if (response.status === 401 && !options.skipUnauthorizedHandler) {
      onUnauthorized?.();
    }
    const body = (payload ?? {}) as { message?: string; errors?: Record<string, string[]> };
    throw new ApiError(messageFor(response.status, body.message), response.status, body.errors);
  }

  return payload as T;
}

/** Downloads a file (such as a PDF) with the session token. */
export async function apiBlob(path: string): Promise<Blob> {
  const headers: Record<string, string> = {};
  const token = tokenStore.get();
  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }

  let response: Response;
  try {
    response = await fetch(buildUrl(path), { headers });
  } catch {
    throw new ApiError('تعذر الاتصال بالسيرفر، راجع الإنترنت وحاول تاني', 0);
  }

  if (!response.ok) {
    if (response.status === 401) {
      onUnauthorized?.();
    }
    const payload = (await response.json().catch(() => ({}))) as { message?: string };
    throw new ApiError(messageFor(response.status, payload.message), response.status);
  }

  return response.blob();
}

function messageFor(status: number, serverMessage?: string): string {
  if (status === 403) return 'ملكش صلاحية للعملية دي';
  if (status === 404) return 'مش موجود';
  if (status === 429) return 'محاولات كتير، استنى شوية وحاول تاني';
  if (status >= 500) return 'حصلت مشكلة في السيرفر، حاول تاني';

  return serverMessage ?? 'حصل خطأ';
}
