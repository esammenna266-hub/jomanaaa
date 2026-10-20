<?php

namespace Tests\Feature\Finance;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Database-level guarantees from database/sql: the ledger and seat map must be safe even if app code is wrong.
 */
class LedgerIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected bool $dropViews = true;

    private function seedTreasuryAndPayment(): int
    {
        $branchId = DB::table('branches')->value('id');
        $treasuryId = DB::table('treasuries')->insertGetId(['branch_id' => $branchId, 'currency_id' => 1, 'method' => 'cash']);

        return DB::table('payments')->insertGetId([
            'serial_no' => 'P1', 'direction' => 'in', 'kind' => 'collection',
            'treasury_id' => $treasuryId, 'amount' => 100, 'currency_id' => 1,
        ]);
    }

    public function test_payments_cannot_be_updated_or_deleted(): void
    {
        $this->seedTreasuryAndPayment();

        try {
            DB::table('payments')->update(['amount' => 1]);
            $this->fail('payment update was allowed');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        $this->expectException(QueryException::class);
        DB::table('payments')->delete();
    }

    public function test_payment_currency_must_match_the_treasury_currency(): void
    {
        $paymentId = $this->seedTreasuryAndPayment();
        $treasuryId = DB::table('payments')->where('id', $paymentId)->value('treasury_id');

        $this->expectException(QueryException::class);

        DB::table('payments')->insert([
            'serial_no' => 'P2', 'direction' => 'in', 'kind' => 'collection',
            'treasury_id' => $treasuryId, 'amount' => 50, 'currency_id' => 2,
        ]);
    }

    public function test_treasury_balance_is_derived_and_a_reversal_nets_to_zero(): void
    {
        $paymentId = $this->seedTreasuryAndPayment();
        $treasuryId = DB::table('payments')->where('id', $paymentId)->value('treasury_id');

        $this->assertEquals(100, DB::table('v_treasury_balances')->where('treasury_id', $treasuryId)->value('balance'));

        DB::table('payments')->insert([
            'serial_no' => 'P2', 'direction' => 'out', 'kind' => 'reversal', 'treasury_id' => $treasuryId,
            'amount' => 100, 'currency_id' => 1, 'reversal_of_id' => $paymentId,
        ]);

        $this->assertEquals(0, DB::table('v_treasury_balances')->where('treasury_id', $treasuryId)->value('balance'));

        $this->expectException(QueryException::class);
        DB::table('payments')->insert([
            'serial_no' => 'P3', 'direction' => 'out', 'kind' => 'reversal', 'treasury_id' => $treasuryId,
            'amount' => 100, 'currency_id' => 1, 'reversal_of_id' => $paymentId,
        ]);
    }

    public function test_a_seat_cannot_be_double_booked_but_frees_up_after_cancellation(): void
    {
        $vehicleId = DB::table('vehicles')->insertGetId(['plate_no' => 'BUS1', 'category' => 'bus', 'seats' => 52]);
        $tripId = DB::table('trips')->insertGetId([
            'kind' => 'bus', 'route' => 'Cairo-Khartoum', 'depart_at' => now()->addDay(), 'vehicle_id' => $vehicleId,
            'capacity' => 52, 'passenger_seats' => 48, 'express_seats' => 4, 'price' => 100, 'currency_id' => 1,
        ]);
        $booking = fn (string $serial) => DB::table('bookings')->insertGetId([
            'serial_no' => $serial, 'trip_id' => $tripId, 'seat_no' => 5, 'passenger_name' => 'Ali',
            'sale_price' => 100, 'currency_id' => 1,
        ]);

        $first = $booking('B1');

        try {
            $booking('B2');
            $this->fail('double booking was allowed');
        } catch (QueryException) {
            // expected
        }

        DB::table('bookings')->where('id', $first)->update(['status' => 'cancelled']);

        $this->assertGreaterThan($first, $booking('B3'));
    }
}
