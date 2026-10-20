<?php

namespace App\Modules\Finance\Console;

use App\Modules\Finance\Models\Installment;
use Illuminate\Console\Command;

class MarkOverdueInstallmentsCommand extends Command
{
    protected $signature = 'gomana:mark-overdue-installments';

    protected $description = 'Flag unpaid installments whose due date has passed as overdue (AC-03)';

    public function handle(): int
    {
        $count = 0;

        Installment::where('status', 'due')
            ->whereDate('due_date', '<', today())
            ->get()
            ->each(function (Installment $installment) use (&$count): void {
                $installment->update(['status' => 'overdue']);
                $count++;
            });

        $this->info("{$count} installment(s) marked overdue.");

        return self::SUCCESS;
    }
}
