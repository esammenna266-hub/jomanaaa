<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Models\Branch;
use App\Support\SerialNumberService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditAndSerialTest extends TestCase
{
    use RefreshDatabase;

    protected bool $dropViews = true;

    public function test_creating_and_updating_records_writes_audit_logs_with_the_actor(): void
    {
        $user = $this->signInAs('admin');

        $id = $this->postJson('/api/v1/destinations', ['name' => 'Kosti', 'country' => 'SD'])->json('data.id');
        $this->putJson("/api/v1/destinations/{$id}", ['name' => 'Kosti City'])->assertOk();

        $created = DB::table('audit_logs')->where(['entity' => 'destinations', 'entity_id' => $id, 'action' => 'created'])->first();
        $updated = DB::table('audit_logs')->where(['entity' => 'destinations', 'entity_id' => $id, 'action' => 'updated'])->first();

        $this->assertSame($user->id, $created->user_id);
        $this->assertSame('Kosti', json_decode($created->after_json, true)['name']);
        $this->assertSame('Kosti', json_decode($updated->before_json, true)['name']);
        $this->assertSame('Kosti City', json_decode($updated->after_json, true)['name']);
    }

    public function test_password_hashes_never_reach_the_audit_log(): void
    {
        $this->signInAs('owner');
        $roleId = DB::table('roles')->where('code', 'accountant')->value('id');

        $this->postJson('/api/v1/users', [
            'name' => 'Acc', 'username' => 'acc1', 'password' => 'long-enough-1', 'role_id' => $roleId,
        ])->assertCreated();

        $entry = DB::table('audit_logs')->where(['entity' => 'users', 'action' => 'created'])->orderByDesc('id')->first();
        $this->assertStringNotContainsString('password', $entry->after_json);
    }

    public function test_audit_log_is_append_only(): void
    {
        $this->signInAs('owner');
        Branch::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('audit_logs')->update(['action' => 'tampered']);
    }

    public function test_audit_log_viewer_filters_by_entity(): void
    {
        $this->signInAs('owner');
        Branch::factory()->create();

        $this->getJson('/api/v1/audit-logs?entity=branches')->assertOk()
            ->assertJsonPath('data.0.entity', 'branches');
    }

    public function test_serials_are_sequential_and_zero_padded(): void
    {
        $service = new SerialNumberService;

        $this->assertSame('000001', $service->next('receipt'));
        $this->assertSame('000002', $service->next('receipt'));
        $this->assertSame('000001', $service->next('waybill'));
    }

    public function test_rolled_back_transaction_does_not_burn_a_number(): void
    {
        $service = new SerialNumberService;

        try {
            DB::transaction(function () use ($service): void {
                $service->next('voucher');
                throw new \RuntimeException('save failed');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame('000001', $service->next('voucher'));
    }

    public function test_unknown_document_type_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SerialNumberService)->next('nonsense');
    }
}
