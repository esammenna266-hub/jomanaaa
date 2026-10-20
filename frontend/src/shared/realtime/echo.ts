import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { tokenStore } from '../api/client';

/**
 * The live-update connection (Laravel Reverb). It needs VITE_REVERB_APP_KEY; without it, or when the socket server is
 * not running, screens fall back to refreshing on a timer, so nothing depends on it.
 */

let instance: Echo<'reverb'> | null = null;
let instanceToken: string | null = null;

/** The shared connection for the signed-in user, or null when live updates are not set up. */
export function getEcho(): Echo<'reverb'> | null {
  const token = tokenStore.get();
  // Read when used (not when loaded) so tests and a rebuilt app pick up the current settings.
  const KEY = import.meta.env.VITE_REVERB_APP_KEY as string | undefined;
  const BASE_URL = ((import.meta.env.VITE_API_URL as string | undefined) ?? '').replace(/\/$/, '');

  if (!KEY || !token) {
    return null;
  }

  // A new sign-in has a new token: the old connection was authorised for someone else.
  if (instance !== null && instanceToken !== token) {
    resetEcho();
  }

  if (instance === null) {
    (window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher;
    const scheme = (import.meta.env.VITE_REVERB_SCHEME as string | undefined) ?? 'http';
    const port = Number(import.meta.env.VITE_REVERB_PORT ?? (scheme === 'https' ? 443 : 8080));

    instance = new Echo({
      broadcaster: 'reverb',
      key: KEY,
      wsHost: (import.meta.env.VITE_REVERB_HOST as string | undefined) ?? window.location.hostname,
      wsPort: port,
      wssPort: port,
      forceTLS: scheme === 'https',
      enabledTransports: ['ws', 'wss'],
      authEndpoint: `${BASE_URL}/api/v1/broadcasting/auth`,
      auth: { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } },
    });
    instanceToken = token;
  }

  return instance;
}

/** Closes the connection (sign-out, or a different user signing in). */
export function resetEcho(): void {
  instance?.disconnect();
  instance = null;
  instanceToken = null;
}
