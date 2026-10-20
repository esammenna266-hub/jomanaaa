import { useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { getEcho } from '../../shared/realtime/echo';

/** How often a seat map refreshes by itself: slowly when live updates are connected, quickly when they are not. */
export const REFRESH_WHEN_LIVE_MS = 60_000;
export const REFRESH_WHEN_NOT_LIVE_MS = 15_000;

/**
 * Keeps a trip's screens current. When the live connection is up, the server announces every change to the trip and
 * the screens ask the API again; otherwise the caller's timer does the job. Returns whether live updates are connected.
 */
export function useLiveTrip(tripId: number): boolean {
  const queryClient = useQueryClient();
  const [live, setLive] = useState(false);

  useEffect(() => {
    const echo = getEcho();
    if (echo === null) {
      setLive(false);

      return;
    }

    const refresh = () => void queryClient.invalidateQueries({ queryKey: ['passenger-trip', tripId] });
    const channelName = `passenger-trip.${tripId}`;
    echo.private(channelName).listen('.seats.changed', refresh);

    // Track the socket so the screen can say whether it is live, and catch up after a reconnect.
    const pusher = (echo.connector as unknown as { pusher?: { connection: { state: string; bind: (event: string, callback: (states: { current: string }) => void) => void; unbind: (event: string, callback: (states: { current: string }) => void) => void } } }).pusher;
    const onState = (states: { current: string }) => {
      const connected = states.current === 'connected';
      setLive(connected);
      if (connected) refresh();
    };
    setLive(pusher?.connection.state === 'connected');
    pusher?.connection.bind('state_change', onState);

    return () => {
      pusher?.connection.unbind('state_change', onState);
      echo.leave(channelName);
    };
  }, [tripId, queryClient]);

  return live;
}
