<?php

namespace App\Modules\Booking\Console;

use App\Modules\Booking\Services\PassengerTripService;
use Illuminate\Console\Command;

class LockExpressTripsCommand extends Command
{
    protected $signature = 'gomana:lock-express-trips';

    protected $description = 'Freeze the express (cargo) seats of trips whose cutoff has passed (BS-09)';

    public function handle(PassengerTripService $trips): int
    {
        $this->info("{$trips->lockExpiredExpress()} trip(s) locked.");

        return self::SUCCESS;
    }
}
