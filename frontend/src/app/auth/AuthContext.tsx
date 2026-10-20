import { useQueryClient } from '@tanstack/react-query';
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { api, setUnauthorizedHandler, tokenStore } from '../../shared/api/client';
import type { CurrentUser, Scope } from '../../shared/api/types';

export type AuthStatus = 'loading' | 'authenticated' | 'anonymous';

export interface AuthValue {
  status: AuthStatus;
  user: CurrentUser | null;
  login: (username: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  reloadUser: () => Promise<void>;
  /** True when the user holds the permission at any scope. */
  can: (permission: string) => boolean;
  scopeOf: (permission: string) => Scope | null;
}

export const AuthContext = createContext<AuthValue | null>(null);

export function useAuth(): AuthValue {
  const value = useContext(AuthContext);
  if (value === null) {
    throw new Error('useAuth must be used inside <AuthProvider>');
  }

  return value;
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient();
  const [user, setUser] = useState<CurrentUser | null>(null);
  const [status, setStatus] = useState<AuthStatus>(tokenStore.get() ? 'loading' : 'anonymous');

  const clearSession = useCallback(() => {
    tokenStore.clear();
    setUser(null);
    setStatus('anonymous');
    queryClient.clear();
  }, [queryClient]);

  // A 401 from any call means the token expired or was revoked.
  useEffect(() => {
    setUnauthorizedHandler(clearSession);

    return () => setUnauthorizedHandler(null);
  }, [clearSession]);

  const reloadUser = useCallback(async () => {
    const { user: current } = await api<{ user: CurrentUser }>('/auth/me');
    setUser(current);
    setStatus('authenticated');
  }, []);

  useEffect(() => {
    if (status !== 'loading') {
      return;
    }
    reloadUser().catch(() => clearSession());
    // Only the first load restores the session; later changes go through login/logout.
  }, []);

  const login = useCallback(async (username: string, password: string) => {
    const response = await api<{ token: string; user: CurrentUser }>('/auth/login', {
      method: 'POST',
      body: { username, password },
      skipUnauthorizedHandler: true,
    });
    tokenStore.set(response.token);
    setUser(response.user);
    setStatus('authenticated');
  }, []);

  const logout = useCallback(async () => {
    try {
      await api('/auth/logout', { method: 'POST', skipUnauthorizedHandler: true });
    } catch {
      // the local session is cleared either way
    }
    clearSession();
  }, [clearSession]);

  const value = useMemo<AuthValue>(
    () => ({
      status,
      user,
      login,
      logout,
      reloadUser,
      can: (permission) => user?.permissions[permission] !== undefined,
      scopeOf: (permission) => user?.permissions[permission] ?? null,
    }),
    [status, user, login, logout, reloadUser],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
