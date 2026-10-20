import { afterEach, describe, expect, it, vi } from 'vitest';
import { mockApi } from '../../test/utils';
import { ApiError, api, setUnauthorizedHandler, tokenStore } from './client';

afterEach(() => {
  setUnauthorizedHandler(null);
  vi.unstubAllGlobals();
});

/** Awaits a call that must fail and returns its error. */
async function failure(call: Promise<unknown>): Promise<ApiError> {
  try {
    await call;
  } catch (error) {
    return error as ApiError;
  }

  throw new Error('expected the call to fail');
}

describe('api client', () => {
  it('sends the bearer token and drops empty query parameters', async () => {
    tokenStore.set('abc123');
    const calls = mockApi([{ path: '/customers', body: { data: [] } }]);

    await api('/customers', { query: { q: '010', page: 2, empty: '', missing: undefined, off: null } });

    expect(calls[0]?.headers.Authorization).toBe('Bearer abc123');
    expect(calls[0]?.query.toString()).toBe('q=010&page=2');
  });

  it('turns a 422 into an ApiError that exposes field messages', async () => {
    mockApi([
      {
        method: 'POST',
        path: '/customers',
        status: 422,
        body: { message: 'invalid', errors: { phone: ['العميل ده متسجل قبل كده'] } },
      },
    ]);

    const error = await api('/customers', { method: 'POST', body: {} }).catch((caught: unknown) => caught);

    expect(error).toBeInstanceOf(ApiError);
    expect((error as ApiError).status).toBe(422);
    expect((error as ApiError).fieldError('phone')).toBe('العميل ده متسجل قبل كده');
    expect((error as ApiError).fieldError('name')).toBeUndefined();
  });

  it('calls the unauthorized handler on 401, except for the login call', async () => {
    const handler = vi.fn();
    setUnauthorizedHandler(handler);
    mockApi([
      { path: '/auth/me', status: 401, body: { message: 'Unauthenticated.' } },
      { method: 'POST', path: '/auth/login', status: 401, body: { message: 'no' } },
    ]);

    await api('/auth/me').catch(() => undefined);
    expect(handler).toHaveBeenCalledTimes(1);

    await api('/auth/login', { method: 'POST', body: {}, skipUnauthorizedHandler: true }).catch(() => undefined);
    expect(handler).toHaveBeenCalledTimes(1);
  });

  it('reports a connection failure in Arabic', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')));

    const error = await api('/customers').catch((caught: unknown) => caught);

    expect(error).toBeInstanceOf(ApiError);
    expect((error as ApiError).status).toBe(0);
    expect((error as ApiError).message).toContain('تعذر الاتصال');
  });

  it('uses friendly messages for permission and server errors', async () => {
    mockApi([
      { path: '/branches', status: 403, body: { message: 'This action is unauthorized.' } },
      { path: '/users', status: 500, body: { message: 'SQLSTATE[...] leaked detail' } },
    ]);

    const forbidden = await failure(api('/branches'));
    const broken = await failure(api('/users'));

    expect(forbidden.message).toBe('ملكش صلاحية للعملية دي');
    expect(broken.message).not.toContain('SQLSTATE');
  });
});
