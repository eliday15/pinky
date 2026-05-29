<?php

namespace Tests\Feature\Api;

use App\Jobs\SyncZktecoJob;
use App\Models\SyncLog;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\FeatureTestCase;

/**
 * Front-to-back contract tests for the machine-to-machine sync agent API.
 *
 * These endpoints are JSON-only and authenticate via a shared Bearer token
 * (config('zkteco.sync.agent_key')) enforced by App\Http\Middleware\SyncAgentAuth.
 * There is NO session/actingAs here — the agent is a headless client.
 *
 * Routes (default /api prefix + /sync-agent group):
 *   GET  /api/sync-agent/poll
 *   POST /api/sync-agent/{syncLog}/start
 *   POST /api/sync-agent/{syncLog}/done
 *   POST /api/sync-agent/heartbeat
 */
class SyncAgentControllerTest extends FeatureTestCase
{
    private const AGENT_KEY = 'test-key';

    protected function setUp(): void
    {
        parent::setUp();

        // Configure a known agent key so the auth middleware accepts our token.
        config(['zkteco.sync.agent_key' => self::AGENT_KEY]);
    }

    // ----------------------------------------------------------------------
    // Authentication middleware (SyncAgentAuth)
    // ----------------------------------------------------------------------

    public function test_poll_without_bearer_token_is_unauthorized(): void
    {
        $this->getJson('/api/sync-agent/poll')
            ->assertUnauthorized()
            ->assertExactJson(['error' => 'Unauthorized']);
    }

    public function test_poll_with_wrong_bearer_token_is_unauthorized(): void
    {
        $this->withToken('wrong-key')
            ->getJson('/api/sync-agent/poll')
            ->assertUnauthorized()
            ->assertExactJson(['error' => 'Unauthorized']);
    }

    public function test_endpoints_unauthorized_when_agent_key_unset_even_with_matching_empty_token(): void
    {
        // When the server has no agent_key configured, ALL requests must be
        // rejected — even a literal empty bearer token must not slip through.
        config(['zkteco.sync.agent_key' => '']);

        $this->withToken('')
            ->getJson('/api/sync-agent/poll')
            ->assertUnauthorized()
            ->assertExactJson(['error' => 'Unauthorized']);

        $this->getJson('/api/sync-agent/poll')
            ->assertUnauthorized();
    }

    public function test_start_without_bearer_token_is_unauthorized(): void
    {
        $log = SyncLog::factory()->requested()->create();

        $this->postJson("/api/sync-agent/{$log->id}/start")
            ->assertUnauthorized();
    }

    public function test_done_without_bearer_token_is_unauthorized(): void
    {
        $log = SyncLog::factory()->running()->create();

        $this->postJson("/api/sync-agent/{$log->id}/done", ['success' => true])
            ->assertUnauthorized();
    }

    public function test_heartbeat_without_bearer_token_is_unauthorized(): void
    {
        $this->postJson('/api/sync-agent/heartbeat')
            ->assertUnauthorized();
    }

    public function test_start_with_wrong_bearer_token_is_unauthorized(): void
    {
        $log = SyncLog::factory()->requested()->create();

        $this->withToken('wrong-key')
            ->postJson("/api/sync-agent/{$log->id}/start")
            ->assertUnauthorized()
            ->assertExactJson(['error' => 'Unauthorized']);

        // Auth runs before any state change; status must remain untouched.
        $this->assertDatabaseHas('sync_logs', [
            'id' => $log->id,
            'status' => 'requested',
        ]);
    }

    public function test_done_with_wrong_bearer_token_is_unauthorized(): void
    {
        Bus::fake();

        $log = SyncLog::factory()->running()->create();

        $this->withToken('wrong-key')
            ->postJson("/api/sync-agent/{$log->id}/done", ['success' => true])
            ->assertUnauthorized()
            ->assertExactJson(['error' => 'Unauthorized']);

        $this->assertDatabaseHas('sync_logs', [
            'id' => $log->id,
            'status' => 'running',
        ]);
        Bus::assertNotDispatched(SyncZktecoJob::class);
    }

    public function test_heartbeat_with_wrong_bearer_token_is_unauthorized(): void
    {
        Cache::forget('sync_agent_last_heartbeat');

        $this->withToken('wrong-key')
            ->postJson('/api/sync-agent/heartbeat')
            ->assertUnauthorized()
            ->assertExactJson(['error' => 'Unauthorized']);

        // Rejected requests must not record a heartbeat.
        $this->assertNull(Cache::get('sync_agent_last_heartbeat'));
    }

    public function test_wrong_token_on_existing_synclog_is_rejected_without_state_change(): void
    {
        // Auth still protects the handler: even though SubstituteBindings runs
        // before SyncAgentAuth (see route:list -vv), an EXISTING syncLog with a
        // bad token is rejected 401 and never transitions.
        $log = SyncLog::factory()->requested()->create();

        $this->withToken('wrong-key')
            ->postJson("/api/sync-agent/{$log->id}/start")
            ->assertUnauthorized()
            ->assertExactJson(['error' => 'Unauthorized']);

        $this->assertSame('requested', $log->fresh()->status);
    }

    public function test_route_model_binding_resolves_before_auth_for_missing_synclog(): void
    {
        // CONTRACT NOTE: SubstituteBindings is registered before SyncAgentAuth,
        // so a request for a NON-EXISTENT syncLog returns 404 regardless of the
        // bearer token (the model is resolved before the auth check runs).
        // This is documented so a future middleware-ordering change is caught.
        // See workflow report: low-severity existence-probe observation.
        $this->withToken('wrong-key')
            ->postJson('/api/sync-agent/999999/start')
            ->assertNotFound();

        $this->getJson('/api/sync-agent/999999/start') // GET on a POST-only route
            ->assertStatus(405);
    }

    // ----------------------------------------------------------------------
    // poll
    // ----------------------------------------------------------------------

    public function test_poll_returns_null_data_when_no_requested_sync(): void
    {
        // A completed sync should not be picked up by poll.
        SyncLog::factory()->completed()->create();

        $this->withToken(self::AGENT_KEY)
            ->getJson('/api/sync-agent/poll')
            ->assertOk()
            ->assertExactJson(['data' => null]);
    }

    public function test_poll_returns_oldest_requested_sync(): void
    {
        // Older requested log should win regardless of insert order.
        $newer = SyncLog::factory()->requested()->create([
            'created_at' => now()->subMinutes(1),
        ]);
        $older = SyncLog::factory()->requested()->create([
            'created_at' => now()->subMinutes(30),
        ]);
        // A running and a completed log must be ignored by poll.
        SyncLog::factory()->running()->create();
        SyncLog::factory()->completed()->create();

        $this->withToken(self::AGENT_KEY)
            ->getJson('/api/sync-agent/poll')
            ->assertOk()
            ->assertJsonPath('data.id', $older->id)
            ->assertJsonPath('data.status', 'requested');
    }

    public function test_poll_exposes_fields_the_agent_consumes(): void
    {
        // The agent reads the serialized SyncLog to know what to fetch.
        // Lock the contract on the keys/values it depends on.
        $user = $this->adminUser();
        $log = SyncLog::factory()->requested()->create([
            'type' => 'zkteco',
            'triggered_by' => $user->id,
        ]);

        $this->withToken(self::AGENT_KEY)
            ->getJson('/api/sync-agent/poll')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'type',
                    'status',
                    'triggered_by',
                    'started_at',
                    'completed_at',
                    'records_fetched',
                ],
            ])
            ->assertJsonPath('data.id', $log->id)
            ->assertJsonPath('data.type', 'zkteco')
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.triggered_by', $user->id);
    }

    // ----------------------------------------------------------------------
    // start
    // ----------------------------------------------------------------------

    public function test_start_transitions_requested_to_running(): void
    {
        $log = SyncLog::factory()->requested()->create();

        $this->withToken(self::AGENT_KEY)
            ->postJson("/api/sync-agent/{$log->id}/start")
            ->assertOk()
            ->assertJsonPath('data.id', $log->id)
            ->assertJsonPath('data.status', 'running')
            ->assertJsonPath('data.started_at', fn ($v) => $v !== null);

        $this->assertDatabaseHas('sync_logs', [
            'id' => $log->id,
            'status' => 'running',
        ]);
        $this->assertNotNull($log->fresh()->started_at);
    }

    public function test_start_conflicts_when_not_requested(): void
    {
        $log = SyncLog::factory()->running()->create();

        $this->withToken(self::AGENT_KEY)
            ->postJson("/api/sync-agent/{$log->id}/start")
            ->assertStatus(409)
            ->assertJsonStructure(['error']);

        // Status must remain unchanged.
        $this->assertDatabaseHas('sync_logs', [
            'id' => $log->id,
            'status' => 'running',
        ]);
    }

    public function test_start_conflicts_when_already_completed(): void
    {
        // Any non-'requested' status (here: completed) must 409, not re-run.
        $log = SyncLog::factory()->completed()->create();

        $this->withToken(self::AGENT_KEY)
            ->postJson("/api/sync-agent/{$log->id}/start")
            ->assertStatus(409)
            ->assertJsonStructure(['error']);

        $log->refresh();
        $this->assertSame('completed', $log->status);
    }

    public function test_start_for_missing_sync_log_returns_404(): void
    {
        $this->withToken(self::AGENT_KEY)
            ->postJson('/api/sync-agent/999999/start')
            ->assertNotFound();
    }

    // ----------------------------------------------------------------------
    // done — success path (dispatches the processing job)
    // ----------------------------------------------------------------------

    public function test_done_success_updates_records_and_dispatches_job(): void
    {
        Bus::fake();

        $log = SyncLog::factory()->running()->create();

        $this->withToken(self::AGENT_KEY)
            ->postJson("/api/sync-agent/{$log->id}/done", [
                'success' => true,
                'devices_synced' => 3,
                'devices_failed' => 0,
                'total_users' => 40,
                'total_attendance' => 123,
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $log->id)
            ->assertJsonPath('data.records_fetched', 123);

        $this->assertDatabaseHas('sync_logs', [
            'id' => $log->id,
            'records_fetched' => 123,
            // Status is intentionally NOT changed to 'completed' here —
            // the dispatched job finalizes it. It stays 'running'.
            'status' => 'running',
        ]);

        Bus::assertDispatched(SyncZktecoJob::class, function (SyncZktecoJob $job) use ($log) {
            return $job->syncLogId === $log->id;
        });
    }

    public function test_done_success_without_optional_stats_defaults_records_to_zero(): void
    {
        Bus::fake();

        $log = SyncLog::factory()->running()->create([
            'records_fetched' => 99,
        ]);

        $this->withToken(self::AGENT_KEY)
            ->postJson("/api/sync-agent/{$log->id}/done", [
                'success' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.records_fetched', 0);

        $this->assertDatabaseHas('sync_logs', [
            'id' => $log->id,
            'records_fetched' => 0,
        ]);

        Bus::assertDispatched(SyncZktecoJob::class);
    }

    // ----------------------------------------------------------------------
    // done — failure path (no job dispatched)
    // ----------------------------------------------------------------------

    public function test_done_failure_marks_failed_with_error_payload(): void
    {
        Bus::fake();

        $log = SyncLog::factory()->running()->create();

        $this->withToken(self::AGENT_KEY)
            ->postJson("/api/sync-agent/{$log->id}/done", [
                'success' => false,
                'error' => 'Device 2 unreachable',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $log->id)
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.errors.agent_error', 'Device 2 unreachable');

        $this->assertDatabaseHas('sync_logs', [
            'id' => $log->id,
            'status' => 'failed',
        ]);
        $this->assertNotNull($log->fresh()->completed_at);

        // Failure must NOT trigger downstream processing.
        Bus::assertNotDispatched(SyncZktecoJob::class);
    }

    public function test_done_failure_without_error_message_uses_default(): void
    {
        Bus::fake();

        $log = SyncLog::factory()->running()->create();

        $this->withToken(self::AGENT_KEY)
            ->postJson("/api/sync-agent/{$log->id}/done", [
                'success' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.errors.agent_error', 'Unknown agent error');

        Bus::assertNotDispatched(SyncZktecoJob::class);
    }

    // ----------------------------------------------------------------------
    // done — conflicts & validation
    // ----------------------------------------------------------------------

    public function test_done_conflicts_when_not_running(): void
    {
        $log = SyncLog::factory()->requested()->create();

        $this->withToken(self::AGENT_KEY)
            ->postJson("/api/sync-agent/{$log->id}/done", ['success' => true])
            ->assertStatus(409)
            ->assertJsonStructure(['error']);

        $this->assertDatabaseHas('sync_logs', [
            'id' => $log->id,
            'status' => 'requested',
        ]);
    }

    public function test_done_requires_success_field(): void
    {
        $log = SyncLog::factory()->running()->create();

        $this->withToken(self::AGENT_KEY)
            ->postJson("/api/sync-agent/{$log->id}/done", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['success']);
    }

    public function test_done_rejects_negative_stat_values(): void
    {
        $log = SyncLog::factory()->running()->create();

        $this->withToken(self::AGENT_KEY)
            ->postJson("/api/sync-agent/{$log->id}/done", [
                'success' => true,
                'devices_synced' => -1,
                'total_attendance' => -5,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['devices_synced', 'total_attendance']);
    }

    public function test_done_for_missing_sync_log_returns_404(): void
    {
        $this->withToken(self::AGENT_KEY)
            ->postJson('/api/sync-agent/999999/done', ['success' => true])
            ->assertNotFound();
    }

    public function test_done_rejects_non_boolean_success(): void
    {
        $log = SyncLog::factory()->running()->create();

        $this->withToken(self::AGENT_KEY)
            ->postJson("/api/sync-agent/{$log->id}/done", [
                'success' => 'maybe',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['success']);
    }

    public function test_done_rejects_remaining_negative_stat_values(): void
    {
        // devices_failed and total_users are also min:0 — cover them too.
        $log = SyncLog::factory()->running()->create();

        $this->withToken(self::AGENT_KEY)
            ->postJson("/api/sync-agent/{$log->id}/done", [
                'success' => true,
                'devices_failed' => -2,
                'total_users' => -3,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['devices_failed', 'total_users']);
    }

    public function test_done_rejects_overlong_error_message(): void
    {
        $log = SyncLog::factory()->running()->create();

        $this->withToken(self::AGENT_KEY)
            ->postJson("/api/sync-agent/{$log->id}/done", [
                'success' => false,
                'error' => str_repeat('x', 2001),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['error']);
    }

    public function test_done_success_dispatches_job_with_triggered_by_and_synclog_id(): void
    {
        Bus::fake();

        $user = $this->adminUser();
        $log = SyncLog::factory()->running()->create([
            'triggered_by' => $user->id,
        ]);

        $this->withToken(self::AGENT_KEY)
            ->postJson("/api/sync-agent/{$log->id}/done", [
                'success' => true,
                'total_attendance' => 7,
            ])
            ->assertOk();

        // Remote-mode contract: job carries both syncLogId and the original
        // triggering user so downstream processing attributes correctly.
        Bus::assertDispatched(SyncZktecoJob::class, function (SyncZktecoJob $job) use ($log, $user) {
            return $job->syncLogId === $log->id
                && $job->triggeredBy === $user->id;
        });
    }

    public function test_done_failure_does_not_overwrite_records_fetched(): void
    {
        Bus::fake();

        // On failure the controller only sets status/completed_at/errors and
        // must not touch records_fetched.
        $log = SyncLog::factory()->running()->create([
            'records_fetched' => 42,
        ]);

        $this->withToken(self::AGENT_KEY)
            ->postJson("/api/sync-agent/{$log->id}/done", [
                'success' => false,
                'error' => 'boom',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'failed');

        $this->assertDatabaseHas('sync_logs', [
            'id' => $log->id,
            'status' => 'failed',
            'records_fetched' => 42,
        ]);
        Bus::assertNotDispatched(SyncZktecoJob::class);
    }

    public function test_done_conflict_when_completed_does_not_dispatch_job(): void
    {
        Bus::fake();

        $log = SyncLog::factory()->completed()->create();

        $this->withToken(self::AGENT_KEY)
            ->postJson("/api/sync-agent/{$log->id}/done", ['success' => true])
            ->assertStatus(409)
            ->assertJsonStructure(['error']);

        $this->assertDatabaseHas('sync_logs', [
            'id' => $log->id,
            'status' => 'completed',
        ]);
        Bus::assertNotDispatched(SyncZktecoJob::class);
    }

    // ----------------------------------------------------------------------
    // heartbeat
    // ----------------------------------------------------------------------

    public function test_heartbeat_returns_ok_and_writes_cache(): void
    {
        Cache::forget('sync_agent_last_heartbeat');

        $this->withToken(self::AGENT_KEY)
            ->postJson('/api/sync-agent/heartbeat')
            ->assertOk()
            ->assertExactJson(['data' => ['status' => 'ok']]);

        $this->assertNotNull(Cache::get('sync_agent_last_heartbeat'));
    }

    public function test_heartbeat_stores_iso8601_timestamp_in_cache(): void
    {
        Cache::forget('sync_agent_last_heartbeat');

        $this->withToken(self::AGENT_KEY)
            ->postJson('/api/sync-agent/heartbeat')
            ->assertOk();

        // The web UI parses this as an ISO8601 timestamp to compute "online".
        $stored = Cache::get('sync_agent_last_heartbeat');
        $this->assertIsString($stored);
        $this->assertNotFalse(
            \Carbon\Carbon::hasFormat($stored, 'Y-m-d\TH:i:sP'),
            "Heartbeat cache value is not ISO8601: {$stored}"
        );
    }
}
