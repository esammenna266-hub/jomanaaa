<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hotel and car bookings are paid through the same ledger as everything else: a payment can now name the service
 * booking it belongs to. A cancelled service booking keeps its reason, like a bus booking does.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('ALTER TABLE payments
            ADD COLUMN service_booking_id BIGINT UNSIGNED NULL AFTER booking_id,
            ADD KEY ix_payments_service_booking (service_booking_id),
            ADD CONSTRAINT fk_pay_service_booking FOREIGN KEY (service_booking_id) REFERENCES service_bookings(id)');

        DB::unprepared('ALTER TABLE service_bookings ADD COLUMN cancel_reason VARCHAR(255) NULL AFTER status');
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE payments DROP FOREIGN KEY fk_pay_service_booking, DROP KEY ix_payments_service_booking, DROP COLUMN service_booking_id');
        DB::unprepared('ALTER TABLE service_bookings DROP COLUMN cancel_reason');
    }
};
