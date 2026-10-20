<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The handover file lists only two example destinations (Omdurman, Wad Madani). These are the main Sudanese cities
 * shipments and passengers go to, plus the Egyptian cities the buses leave from. The company edits the list from
 * "البيانات الأساسية"; rows that already exist are left alone.
 */
return new class extends Migration
{
    private const SUDAN = [
        'الخرطوم', 'الخرطوم بحري', 'بورتسودان', 'عطبرة', 'كسلا', 'القضارف', 'الأبيض', 'كوستي',
        'سنار', 'الدمازين', 'شندي', 'دنقلا', 'الفاشر', 'نيالا', 'الجنينة', 'مروي', 'حلفا',
    ];

    private const EGYPT = ['القاهرة', 'الإسكندرية', 'أسوان'];

    public function up(): void
    {
        foreach ([...array_map(fn (string $name): array => [$name, 'SD'], self::SUDAN), ...array_map(fn (string $name): array => [$name, 'EG'], self::EGYPT)] as [$name, $country]) {
            DB::table('destinations')->insertOrIgnore(['name' => $name, 'country' => $country, 'active' => 1]);
        }
    }

    public function down(): void
    {
        DB::table('destinations')->whereIn('name', [...self::SUDAN, ...self::EGYPT])->delete();
    }
};
