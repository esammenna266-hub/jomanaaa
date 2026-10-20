import { screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ar } from '../../i18n/ar';
import { baseRoutes, seatMap, treasury } from '../../test/fixtures';
import { fakeUser, page, renderApp } from '../../test/utils';

// A stand-in for the socket client: it records the channel and listener, and lets a test play the server's part.
const socket = vi.hoisted(() => ({
  listeners: new Map<string, () => void>(),
  left: [] as string[],
  stateCallbacks: [] as ((states: { current: string }) => void)[],
  state: 'connecting',
}));

vi.mock('laravel-echo', () => ({
  default: class FakeEcho {
    connector = {
      pusher: {
        connection: {
          get state() {
            return socket.state;
          },
          bind: (_event: string, callback: (states: { current: string }) => void) => socket.stateCallbacks.push(callback),
          unbind: () => undefined,
        },
      },
    };
    private(name: string) {
      return {
        listen: (event: string, callback: () => void) => {
          socket.listeners.set(`${name}${event}`, callback);
        },
      };
    }
    leave(name: string) {
      socket.left.push(name);
    }
    disconnect() {}
  },
}));
vi.mock('pusher-js', () => ({ default: class FakePusher {} }));

const staff = fakeUser({ 'trip.view': 'all', 'booking.view': 'all', 'booking.create': 'all' });
const routes = [{ path: '/trips/40/seats', body: seatMap() }, { path: '/treasuries', body: page([treasury()]) }, ...baseRoutes];

beforeEach(() => {
  socket.listeners.clear();
  socket.left.length = 0;
  socket.stateCallbacks.length = 0;
  socket.state = 'connecting';
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.unstubAllEnvs();
});

describe('live seat map', () => {
  it('says it refreshes on a timer when live updates are not set up, and never opens a socket', async () => {
    vi.stubEnv('VITE_REVERB_APP_KEY', '');
    renderApp('/passenger-trips/40', { user: staff, routes });

    expect(await screen.findByText(ar.booking.liveOff)).toBeInTheDocument();
    expect(socket.listeners.size).toBe(0);
  });

  it('listens to the trip channel, goes live when connected and reloads the seats when the server announces a change', async () => {
    vi.stubEnv('VITE_REVERB_APP_KEY', 'test-key');
    const calls = renderApp('/passenger-trips/40', { user: staff, routes });

    await screen.findByText(ar.booking.liveOff);
    await waitFor(() => expect(socket.listeners.has('passenger-trip.40.seats.changed')).toBe(true));

    const seatLoads = () => calls.filter((call) => call.path === '/trips/40/seats').length;
    const before = seatLoads();

    socket.stateCallbacks.forEach((callback) => callback({ current: 'connected' }));
    expect(await screen.findByText(ar.booking.liveOn)).toBeInTheDocument();
    // Catching up after (re)connecting asks the API again.
    await waitFor(() => expect(seatLoads()).toBeGreaterThan(before));

    const afterConnect = seatLoads();
    socket.listeners.get('passenger-trip.40.seats.changed')?.();
    await waitFor(() => expect(seatLoads()).toBeGreaterThan(afterConnect));
  });
});
