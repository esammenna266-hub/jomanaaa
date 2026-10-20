<?php

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Events\SeatMapChanged;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tells the open seat maps that a trip changed. Live updates are a convenience: when the socket server is down or
 * misconfigured the booking itself must still go through, so a failure is only reported, never thrown.
 */
final class SeatMapBroadcaster
{
    /** @var array<int, true> trips already queued in this request, so one booking is announced once */
    private static array $queued = [];

    public static function changed(?int $tripId): void
    {
        if ($tripId === null || isset(self::$queued[$tripId])) {
            return;
        }

        self::$queued[$tripId] = true;

        // After the surrounding transaction commits: a screen that refetches at once must see the new seats.
        DB::afterCommit(function () use ($tripId): void {
            unset(self::$queued[$tripId]);

            try {
                broadcast(new SeatMapChanged($tripId));
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }
}
