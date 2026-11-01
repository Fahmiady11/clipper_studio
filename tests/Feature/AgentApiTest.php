<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeVideoJob;
use App\Jobs\RenderClipJob;
use App\Models\ApiToken;
use App\Models\ClipProject;
use App\Models\GeneratedClip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AgentApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        $this->user = User::factory()->create(['approved_at' => now()]);
        [, $this->token] = ApiToken::issue($this->user, 'test');
    }

    private function api(?string $token = null): static
    {
        return $this->withHeader('Authorization', 'Bearer '.($token ?? $this->token));
    }

    private function clip(User $owner, string $status = 'pending'): GeneratedClip
    {
        $project = ClipProject::create([
            'user_id' => $owner->id, 'youtube_url' => 'https://youtu.be/abcdefghijk',
            'layout_type' => 'reframe', 'clip_count' => 1, 'duration_mode' => 'auto', 'status' => 'done',
        ]);
        Storage::put('clips/a.mp4', 'v');

        return GeneratedClip::create([
            'clip_project_id' => $project->id, 'ranking' => 1, 'start_seconds' => 0, 'end_seconds' => 30,
            'topic' => 't', 'reason' => 'r', 'status' => $status, 'output_path' => $status === 'done' ? 'clips/a.mp4' : null,
        ]);
    }

    public function test_requests_without_a_valid_token_are_rejected(): void
    {
        $this->getJson('/api/v1/projects')->assertUnauthorized();
        $this->api('clp_nope')->getJson('/api/v1/projects')->assertUnauthorized();
    }

    public function test_unapproved_owner_is_refused(): void
    {
        $this->user->forceFill(['approved_at' => null])->save();

        $this->api()->getJson('/api/v1/projects')->assertForbidden();
    }

    public function test_only_the_hash_of_a_token_is_stored(): void
    {
        $this->assertDatabaseMissing('api_tokens', ['token_hash' => $this->token]);
        $this->assertDatabaseHas('api_tokens', ['token_hash' => ApiToken::hash($this->token)]);
    }

    public function test_agent_creates_a_project_with_only_a_url(): void
    {
        $res = $this->api()->postJson('/api/v1/projects', ['youtube_url' => 'https://youtu.be/abcdefghijk'])
            ->assertCreated();

        $this->assertDatabaseHas('clip_projects', [
            'id' => $res->json('project_id'), 'user_id' => $this->user->id, 'layout_type' => 'reframe', 'clip_count' => 3,
        ]);
        Queue::assertPushed(AnalyzeVideoJob::class);
    }

    public function test_agent_lists_clips_and_gets_download_url_when_done(): void
    {
        $clip = $this->clip($this->user, 'done');

        $this->api()->getJson("/api/v1/projects/{$clip->clip_project_id}/clips")
            ->assertOk()
            ->assertJsonPath('clips.0.id', $clip->id)
            ->assertJsonPath('clips.0.download_url', url("/api/v1/clips/{$clip->id}/download"));

        $this->api()->get("/api/v1/clips/{$clip->id}/download")->assertOk();
    }

    public function test_agent_cannot_touch_another_users_project(): void
    {
        $clip = $this->clip(User::factory()->create(['approved_at' => now()]));

        $this->api()->getJson("/api/v1/projects/{$clip->clip_project_id}/clips")->assertNotFound();
        $this->api()->postJson("/api/v1/clips/{$clip->id}/render")->assertForbidden();
    }

    public function test_agent_renders_a_clip(): void
    {
        $clip = $this->clip($this->user);

        $this->api()->postJson("/api/v1/clips/{$clip->id}/render")
            ->assertOk()->assertJsonPath('status', 'processing');
        Queue::assertPushed(RenderClipJob::class);
    }

    public function test_session_api_still_needs_a_session_not_a_token(): void
    {
        $this->api()->getJson('/api/projects')->assertUnauthorized();
    }

    public function test_user_manages_tokens_from_the_web_session(): void
    {
        $this->actingAs($this->user);

        $created = $this->postJson('/api/tokens', ['name' => 'Claude'])->assertCreated();
        $this->assertStringStartsWith('clp_', $created->json('token'));

        $this->getJson('/api/tokens')->assertOk()->assertJsonMissing(['token_hash'])->assertJsonCount(2);

        $this->deleteJson('/api/tokens/'.$created->json('id'))->assertOk();
        $this->assertDatabaseMissing('api_tokens', ['id' => $created->json('id')]);
    }

    public function test_token_page_needs_login_and_renders(): void
    {
        $this->get('/settings/api')->assertRedirect('/login');
        $this->actingAs($this->user)->get('/settings/api')->assertOk()->assertSee('Akses API');
    }

    public function test_user_cannot_revoke_someone_elses_token(): void
    {
        [$other] = ApiToken::issue(User::factory()->create(['approved_at' => now()]), 'x');

        $this->actingAs($this->user)->deleteJson('/api/tokens/'.$other->id)->assertNotFound();
    }

    private function rpc(string $method, array $params = [], int $id = 1)
    {
        return $this->api()->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);
    }

    public function test_mcp_requires_a_token(): void
    {
        $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertUnauthorized();
    }

    public function test_mcp_initialize_and_tool_list(): void
    {
        $this->rpc('initialize', ['protocolVersion' => '2025-03-26'])
            ->assertOk()
            ->assertJsonPath('result.protocolVersion', '2025-03-26')
            ->assertJsonPath('result.serverInfo.name', 'clipper');

        $names = collect($this->rpc('tools/list')->json('result.tools'))->pluck('name')->all();
        foreach (['create_project', 'get_status', 'list_clips', 'render_clip', 'publish'] as $tool) {
            $this->assertContains($tool, $names);
        }
    }

    public function test_mcp_notification_gets_no_body(): void
    {
        $this->api()->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])->assertStatus(202);
    }

    public function test_mcp_create_project_and_list_clips(): void
    {
        $call = $this->rpc('tools/call', ['name' => 'create_project', 'arguments' => ['youtube_url' => 'https://youtu.be/abcdefghijk', 'clip_count' => 1]])
            ->assertOk()->assertJsonPath('result.isError', false);
        $projectId = json_decode($call->json('result.content.0.text'), true)['project_id'];
        $this->assertDatabaseHas('clip_projects', ['id' => $projectId, 'user_id' => $this->user->id, 'clip_count' => 1]);

        $clip = $this->clip($this->user);
        $list = $this->rpc('tools/call', ['name' => 'list_clips', 'arguments' => ['project_id' => $clip->clip_project_id]]);
        $this->assertSame($clip->id, json_decode($list->json('result.content.0.text'), true)['clips'][0]['id']);
    }

    public function test_mcp_tool_errors_are_reported_not_thrown(): void
    {
        $this->rpc('tools/call', ['name' => 'create_project', 'arguments' => ['youtube_url' => 'https://example.com/x']])
            ->assertOk()->assertJsonPath('result.isError', true);

        $clip = $this->clip(User::factory()->create(['approved_at' => now()]));
        $this->rpc('tools/call', ['name' => 'render_clip', 'arguments' => ['clip_id' => $clip->id]])
            ->assertJsonPath('result.isError', true);

        $this->rpc('tools/call', ['name' => 'get_status', 'arguments' => []])
            ->assertJsonPath('result.isError', true);
        $this->rpc('nope')->assertJsonPath('error.code', -32601);
    }

    public function test_polling_does_not_eat_the_project_creation_limit(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->api()->getJson('/api/v1/projects')->assertOk();
        }

        $this->api()->postJson('/api/v1/projects', ['youtube_url' => 'https://youtu.be/abcdefghijk'])->assertCreated();
    }

    public function test_mcp_links_use_the_callers_host(): void
    {
        $clip = $this->clip($this->user, 'done');

        $res = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('https://clipper.test/api/v1/mcp', [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => 'list_clips', 'arguments' => ['project_id' => $clip->clip_project_id]],
            ]);

        $url = json_decode($res->json('result.content.0.text'), true)['clips'][0]['download_url'];
        $this->assertSame("https://clipper.test/api/v1/clips/{$clip->id}/download", $url);
    }
}
