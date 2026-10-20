<?php

namespace App\Modules\Reports\Services;

use App\Modules\Core\Models\Agent;
use App\Modules\Finance\Services\AgentAccountService;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The management and finance reports. Every report is a table: a title, columns and rows of plain values,
 * so the same data feeds the screen and the CSV export. Nothing is stored; each report reads the ledger and the
 * documents as they are. `$branchId` limits a report to one branch (staff with branch scope); null means everything.
 */
final class ReportService
{
    public const METHODS = ['cash' => 'نقدي', 'instapay' => 'إنستاباي', 'vodafone_cash' => 'فودافون كاش'];

    public function __construct(private AgentAccountService $agents) {}

    /**
     * Balance of every treasury right now.
     *
     * @return array{title: string, columns: list<array{key: string, label: string}>, rows: list<array<string, mixed>>}
     */
    public function treasuryBalances(?int $branchId): array
    {
        $rows = DB::table('v_treasury_balances as b')
            ->join('branches', 'branches.id', '=', 'b.branch_id')
            ->join('currencies', 'currencies.id', '=', 'b.currency_id')
            ->when($branchId, fn (Builder $query) => $query->where('b.branch_id', $branchId))
            ->orderBy('branches.name')->orderBy('b.currency_id')->orderBy('b.method')
            ->get(['branches.name as branch', 'b.method', 'currencies.name_ar as currency', 'b.balance'])
            ->map(fn ($row): array => [
                'branch' => $row->branch,
                'method' => self::METHODS[$row->method] ?? $row->method,
                'currency' => $row->currency,
                'balance' => $this->money($row->balance),
            ])->all();

        return $this->table('أرصدة الخزائن', [
            'branch' => 'الفرع', 'method' => 'الخزنة', 'currency' => 'العملة', 'balance' => 'الرصيد',
        ], $rows);
    }

    /**
     * Money in, out and spent per branch and currency in a period.
     *
     * @return array{title: string, columns: list<array{key: string, label: string}>, rows: list<array<string, mixed>>}
     */
    public function branchActivity(?int $branchId, ?string $from, ?string $to): array
    {
        $rows = $this->payments($from, $to)
            ->join('treasuries as t', 't.id', '=', 'payments.treasury_id')
            ->join('branches', 'branches.id', '=', 't.branch_id')
            ->join('currencies', 'currencies.id', '=', 'payments.currency_id')
            ->when($branchId, fn (Builder $query) => $query->where('t.branch_id', $branchId))
            ->groupBy('branches.id', 'branches.name', 'currencies.id', 'currencies.name_ar')
            ->orderBy('branches.name')->orderBy('currencies.id')
            ->selectRaw("branches.name AS branch, currencies.name_ar AS currency,
                SUM(CASE WHEN payments.kind = 'collection' AND payments.direction = 'in' THEN payments.amount ELSE 0 END) AS collected,
                SUM(CASE WHEN payments.kind = 'refund' AND payments.direction = 'out' THEN payments.amount ELSE 0 END) AS refunded,
                SUM(CASE WHEN payments.kind = 'expense' AND payments.direction = 'out' THEN payments.amount ELSE 0 END) AS spent,
                SUM(CASE WHEN payments.kind = 'agent_settlement' AND payments.direction = 'in' THEN payments.amount ELSE 0 END) AS from_offices,
                SUM(CASE payments.direction WHEN 'in' THEN payments.amount ELSE -payments.amount END) AS net")
            ->get()
            ->map(fn ($row): array => [
                'branch' => $row->branch,
                'currency' => $row->currency,
                'collected' => $this->money($row->collected),
                'refunded' => $this->money($row->refunded),
                'spent' => $this->money($row->spent),
                'from_offices' => $this->money($row->from_offices),
                'net' => $this->money($row->net),
            ])->all();

        return $this->table('نشاط الفروع', [
            'branch' => 'الفرع', 'currency' => 'العملة', 'collected' => 'المحصّل', 'refunded' => 'المسترد',
            'spent' => 'المصروفات', 'from_offices' => 'من المكاتب الخارجية', 'net' => 'صافي الحركة',
        ], $rows);
    }

    /**
     * Cancelled shipments and bookings with the reason given.
     *
     * @return array{title: string, columns: list<array{key: string, label: string}>, rows: list<array<string, mixed>>}
     */
    public function cancellations(?int $branchId, ?string $from, ?string $to): array
    {
        $sources = [
            ['شحنة', 'shipments', 'shipments.price - shipments.discount', 'shipments.updated_at'],
            ['حجز مقعد', 'bookings', 'bookings.sale_price', 'bookings.updated_at'],
            ['فندق/سيارة', 'service_bookings', 'service_bookings.price', 'service_bookings.updated_at'],
        ];

        $rows = [];
        foreach ($sources as [$label, $table, $amount, $at]) {
            $query = DB::table($table)
                ->leftJoin('branches', 'branches.id', '=', "{$table}.branch_id")
                ->join('currencies', 'currencies.id', '=', "{$table}.currency_id")
                ->where("{$table}.status", 'cancelled')
                ->when($branchId, fn (Builder $q) => $q->where("{$table}.branch_id", $branchId));
            $this->between($query, $at, $from, $to);

            foreach ($query->orderByDesc("{$table}.id")->get([
                "{$table}.serial_no as serial", "{$table}.cancel_reason as reason", 'branches.name as branch',
                DB::raw("{$at} as at"), DB::raw("{$amount} as amount"), 'currencies.name_ar as currency',
            ]) as $row) {
                $rows[] = [
                    'type' => $label, 'serial' => $row->serial, 'reason' => $row->reason ?? '—', 'branch' => $row->branch ?? 'مكتب خارجي',
                    'at' => $this->date($row->at), 'amount' => $this->money($row->amount), 'currency' => $row->currency,
                ];
            }
        }

        usort($rows, fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        return $this->table('الإلغاءات وأسبابها', [
            'type' => 'النوع', 'serial' => 'الرقم', 'reason' => 'السبب', 'branch' => 'الفرع',
            'at' => 'التاريخ', 'amount' => 'القيمة', 'currency' => 'العملة',
        ], $rows);
    }

    /**
     * Every refund paid out in the period.
     *
     * @return array{title: string, columns: list<array{key: string, label: string}>, rows: list<array<string, mixed>>}
     */
    public function refunds(?int $branchId, ?string $from, ?string $to): array
    {
        $rows = $this->payments($from, $to)
            ->join('treasuries as t', 't.id', '=', 'payments.treasury_id')
            ->join('branches', 'branches.id', '=', 't.branch_id')
            ->join('currencies', 'currencies.id', '=', 'payments.currency_id')
            ->leftJoin('users', 'users.id', '=', 'payments.created_by')
            ->where('payments.kind', 'refund')
            ->where('payments.direction', 'out')
            ->when($branchId, fn (Builder $query) => $query->where('t.branch_id', $branchId))
            ->orderByDesc('payments.id')
            ->get(['payments.serial_no', 'payments.created_at', 'branches.name as branch', 'users.name as employee',
                'payments.amount', 'currencies.name_ar as currency', 'payments.notes'])
            ->map(fn ($row): array => [
                'serial' => $row->serial_no, 'at' => $this->date($row->created_at), 'branch' => $row->branch,
                'employee' => $row->employee ?? '—', 'amount' => $this->money($row->amount), 'currency' => $row->currency, 'notes' => $row->notes ?? '—',
            ])->all();

        return $this->table('المسترد', [
            'serial' => 'رقم الحركة', 'at' => 'التاريخ', 'branch' => 'الفرع', 'employee' => 'الموظف',
            'amount' => 'المبلغ', 'currency' => 'العملة', 'notes' => 'السبب',
        ], $rows);
    }

    /**
     * What each employee collected, refunded and paid out in the period.
     *
     * @return array{title: string, columns: list<array{key: string, label: string}>, rows: list<array<string, mixed>>}
     */
    public function staffMovements(?int $branchId, ?string $from, ?string $to): array
    {
        $rows = $this->payments($from, $to)
            ->join('treasuries as t', 't.id', '=', 'payments.treasury_id')
            ->join('currencies', 'currencies.id', '=', 'payments.currency_id')
            ->leftJoin('users', 'users.id', '=', 'payments.created_by')
            ->when($branchId, fn (Builder $query) => $query->where('t.branch_id', $branchId))
            ->groupBy('users.id', 'users.name', 'currencies.id', 'currencies.name_ar')
            ->orderBy('users.name')->orderBy('currencies.id')
            ->selectRaw("COALESCE(users.name, 'النظام') AS employee, currencies.name_ar AS currency, COUNT(*) AS movements,
                SUM(CASE payments.direction WHEN 'in' THEN payments.amount ELSE 0 END) AS money_in,
                SUM(CASE payments.direction WHEN 'out' THEN payments.amount ELSE 0 END) AS money_out")
            ->get()
            ->map(fn ($row): array => [
                'employee' => $row->employee, 'currency' => $row->currency, 'movements' => (int) $row->movements,
                'money_in' => $this->money($row->money_in), 'money_out' => $this->money($row->money_out),
            ])->all();

        return $this->table('حركات الموظفين', [
            'employee' => 'الموظف', 'currency' => 'العملة', 'movements' => 'عدد الحركات', 'money_in' => 'داخل', 'money_out' => 'خارج',
        ], $rows);
    }

    /**
     * Customers who still owe money on shipments, per currency.
     *
     * @return array{title: string, columns: list<array{key: string, label: string}>, rows: list<array<string, mixed>>}
     */
    public function debts(?int $branchId): array
    {
        $rows = DB::table('v_shipment_balances as b')
            ->join('customers', 'customers.id', '=', 'b.customer_id')
            ->join('currencies', 'currencies.id', '=', 'b.currency_id')
            ->where('b.remaining', '>', 0)
            ->when($branchId, fn (Builder $query) => $query->where('b.branch_id', $branchId))
            ->groupBy('customers.id', 'customers.name', 'customers.phone', 'currencies.id', 'currencies.name_ar')
            ->orderByDesc(DB::raw('SUM(b.remaining)'))
            ->selectRaw('customers.name AS customer, customers.phone AS phone, currencies.name_ar AS currency, COUNT(*) AS shipments,
                SUM(b.total_due) AS due, SUM(b.paid_same_currency) AS paid, SUM(b.remaining) AS remaining')
            ->get()
            ->map(fn ($row): array => [
                'customer' => $row->customer, 'phone' => $row->phone, 'currency' => $row->currency, 'shipments' => (int) $row->shipments,
                'due' => $this->money($row->due), 'paid' => $this->money($row->paid), 'remaining' => $this->money($row->remaining),
            ])->all();

        return $this->table('المديونيات', [
            'customer' => 'العميل', 'phone' => 'التليفون', 'currency' => 'العملة', 'shipments' => 'عدد الشحنات',
            'due' => 'الإجمالي', 'paid' => 'المدفوع', 'remaining' => 'المتبقي',
        ], $rows);
    }

    /**
     * What every external office owes, has paid and still owes. `$onlyAgentId` limits it to one office.
     *
     * @return array{title: string, columns: list<array{key: string, label: string}>, rows: list<array<string, mixed>>}
     */
    public function agentAccounts(?int $onlyAgentId = null): array
    {
        $currencies = DB::table('currencies')->pluck('name_ar', 'id');
        $rows = [];

        foreach (Agent::query()->when($onlyAgentId, fn ($query) => $query->whereKey($onlyAgentId))->orderBy('name')->get(['id', 'name']) as $agent) {
            foreach ($this->agents->balances($agent->id) as $balance) {
                $rows[] = [
                    'agent' => $agent->name, 'currency' => $currencies[$balance['currency_id']] ?? '',
                    'due' => $this->money($balance['due']), 'paid' => $this->money($balance['paid']), 'remaining' => $this->money($balance['remaining']),
                ];
            }
        }

        return $this->table('المكاتب الخارجية', [
            'agent' => 'المكتب', 'currency' => 'العملة', 'due' => 'المستحق للشركة', 'paid' => 'المدفوع', 'remaining' => 'المتبقي',
        ], $rows);
    }

    /**
     * The owner's landing numbers.
     *
     * @return array<string, mixed>
     */
    public function dashboard(?int $branchId): array
    {
        $today = CarbonImmutable::now('Africa/Cairo')->startOfDay()->utc();

        $balances = DB::table('v_treasury_balances as b')
            ->join('currencies', 'currencies.id', '=', 'b.currency_id')
            ->when($branchId, fn (Builder $query) => $query->where('b.branch_id', $branchId))
            ->groupBy('currencies.id', 'currencies.name_ar')->orderBy('currencies.id')
            ->selectRaw('currencies.name_ar AS currency, SUM(b.balance) AS balance')
            ->get()->map(fn ($row): array => ['currency' => $row->currency, 'amount' => $this->money($row->balance)])->all();

        $collectedToday = DB::table('payments')
            ->join('treasuries as t', 't.id', '=', 'payments.treasury_id')
            ->join('currencies', 'currencies.id', '=', 'payments.currency_id')
            ->where('payments.kind', 'collection')->where('payments.direction', 'in')
            ->where('payments.created_at', '>=', $today)
            ->when($branchId, fn (Builder $query) => $query->where('t.branch_id', $branchId))
            ->groupBy('currencies.id', 'currencies.name_ar')->orderBy('currencies.id')
            ->selectRaw('currencies.name_ar AS currency, SUM(payments.amount) AS amount')
            ->get()->map(fn ($row): array => ['currency' => $row->currency, 'amount' => $this->money($row->amount)])->all();

        $shipmentStatuses = DB::table('shipments')
            ->whereNotIn('status', ['draft', 'delivered', 'cancelled'])
            ->when($branchId, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->groupBy('status')->selectRaw('status, COUNT(*) AS total')->pluck('total', 'status')
            ->map(fn ($total): int => (int) $total)->all();

        return [
            'treasury_totals' => $balances,
            'collected_today' => $collectedToday,
            'open_shipments' => $shipmentStatuses,
            'pending_approvals' => $branchId ? null : DB::table('approvals')->where('status', 'pending')->count(),
            'overdue_installments' => DB::table('installments')
                ->join('shipments', 'shipments.id', '=', 'installments.shipment_id')
                ->where('installments.status', 'overdue')
                ->when($branchId, fn (Builder $query) => $query->where('shipments.branch_id', $branchId))
                ->count(),
            'pending_remittances' => DB::table('remittances')->where('status', 'sent')->count(),
            'cargo_trips_on_the_road' => $branchId ? null : DB::table('cargo_trips')->whereNotIn('stage', ['completed', 'cancelled'])->count(),
            'passenger_trips_open' => DB::table('trips')->whereIn('status', ['open', 'express_locked'])->where('depart_at', '>', now())->count(),
            'agents_owing' => $branchId ? null : $this->agentsOwing(),
        ];
    }

    /**
     * Number of offices with a positive balance in any currency.
     */
    private function agentsOwing(): int
    {
        $owing = 0;

        foreach (Agent::pluck('id') as $agentId) {
            if (collect($this->agents->balances($agentId))->contains(fn (array $balance): bool => BigDecimal::of($balance['remaining'])->isPositive())) {
                $owing++;
            }
        }

        return $owing;
    }

    private function payments(?string $from, ?string $to): Builder
    {
        $query = DB::table('payments');
        $this->between($query, 'payments.created_at', $from, $to);

        return $query;
    }

    /**
     * Inclusive day range on a datetime column (the dates are Cairo days).
     */
    private function between(Builder $query, string $column, ?string $from, ?string $to): void
    {
        if ($from !== null) {
            $query->where($column, '>=', CarbonImmutable::parse($from, 'Africa/Cairo')->startOfDay()->utc());
        }

        if ($to !== null) {
            $query->where($column, '<', CarbonImmutable::parse($to, 'Africa/Cairo')->addDay()->startOfDay()->utc());
        }
    }

    private function money(mixed $value): string
    {
        return (string) BigDecimal::of($value ?? 0)->toScale(2);
    }

    private function date(?string $value): string
    {
        return $value === null ? '—' : CarbonImmutable::parse($value, 'UTC')->setTimezone('Africa/Cairo')->format('Y-m-d H:i');
    }

    /**
     * Columns holding money are marked `money` and counts `int`, so the Excel and PDF exports can format and
     * align them; everything else is text.
     *
     * @param  array<string, string>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @return array{title: string, columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>}
     */
    private function table(string $title, array $columns, array $rows): array
    {
        $money = ['balance', 'collected', 'refunded', 'spent', 'from_offices', 'net', 'amount', 'money_in', 'money_out', 'due', 'paid', 'remaining'];
        $counts = ['movements', 'shipments'];

        return [
            'title' => $title,
            'columns' => array_map(fn (string $key, string $label): array => [
                'key' => $key,
                'label' => $label,
                'type' => in_array($key, $money, true) ? 'money' : (in_array($key, $counts, true) ? 'int' : 'text'),
            ], array_keys($columns), array_values($columns)),
            'rows' => $rows,
        ];
    }
}
