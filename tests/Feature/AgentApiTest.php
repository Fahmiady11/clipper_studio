<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeVideoJob;
use App\Jobs\RenderClipJob;
use App\Models\ApiToken;
use App\Models\ClipProject;
use App\Models\GeneratedClip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
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

    public function test_polling_past_the_global_limit_gets_429_with_retry_after(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->api()->postJson('/api/v1/projects', ['youtube_url' => 'https://youtu.be/abcdefghijk'])->assertCreated();
        }

        $this->api()->postJson('/api/v1/projects', ['youtube_url' => 'https://youtu.be/abcdefghijk'])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_one_mcp_tool_call_counts_once_against_the_agent_limit(): void
    {
        $this->rpc('tools/call', ['name' => 'list_publish_accounts', 'arguments' => []])
            ->assertOk()->assertHeader('X-RateLimit-Remaining', '119');
        $this->rpc('tools/call', ['name' => 'list_publish_accounts', 'arguments' => []])
            ->assertHeader('X-RateLimit-Remaining', '118');
    }

    public function test_a_client_cannot_skip_the_throttle_with_a_header_or_param(): void
    {
        $this->withHeader('X-Agent-Internal', '1')->api()->getJson('/api/v1/projects?agent_internal=1')
            ->assertOk()->assertHeader('X-RateLimit-Remaining', '119');
    }

    public function test_mcp_batch_size_is_capped(): void
    {
        $batch = array_fill(0, 21, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);

        $this->api()->postJson('/api/v1/mcp', $batch)->assertJsonPath('error.code', -32600);
        $this->api()->postJson('/api/v1/mcp', array_slice($batch, 0, 20))->assertOk()->assertJsonCount(20);
    }

    public function test_every_agent_route_but_mcp_requires_an_ability(): void
    {
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/') || str_starts_with($route->uri(), 'api/v1/mcp')) {
                continue;
            }
            $this->assertNotEmpty(
                array_filter($route->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'token.can:')),
                $route->methods()[0].' '.$route->uri().' has no token.can middleware',
            );
            $checked++;
        }
        $this->assertSame(17, $checked);
    }

    public function test_read_only_token_can_poll_but_not_spend_or_publish(): void
    {
        [, $ro] = ApiToken::issue($this->user, 'ro', ['read']);
        $clip = $this->clip($this->user, 'done');

        $this->api($ro)->getJson("/api/v1/projects/{$clip->clip_project_id}/clips")->assertOk();
        $this->api($ro)->getJson("/api/v1/clips/{$clip->id}/render-status")->assertOk();
        $this->api($ro)->get("/api/v1/clips/{$clip->id}/download")->assertOk();
        $this->api($ro)->getJson('/api/v1/publish/accounts')->assertOk();

        $this->api($ro)->postJson('/api/v1/projects', ['youtube_url' => 'https://youtu.be/abcdefghijk'])
            ->assertForbidden()->assertJsonPath('required_ability', 'render');
        $this->api($ro)->postJson("/api/v1/clips/{$clip->id}/render")->assertForbidden();
        $this->api($ro)->postJson("/api/v1/clips/{$clip->id}/cancel")->assertForbidden();
        $this->api($ro)->postJson("/api/v1/projects/{$clip->clip_project_id}/cancel")->assertForbidden();
        $this->api($ro)->getJson("/api/v1/clips/{$clip->id}/caption")->assertForbidden();
        $this->api($ro)->postJson("/api/v1/clips/{$clip->id}/youtube", [])->assertForbidden()
            ->assertJsonPath('required_ability', 'publish');
        $this->api($ro)->postJson("/api/v1/clips/{$clip->id}/tiktok", [])->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_render_and_publish_imply_read(): void
    {
        [$renderOnly, $plain] = ApiToken::issue($this->user, 'r', ['render']);
        $clip = $this->clip($this->user, 'done');

        $this->assertSame(['read', 'render'], $renderOnly->abilities);
        $this->api($plain)->getJson("/api/v1/projects/{$clip->clip_project_id}/status")->assertOk();
        $this->api($plain)->getJson("/api/v1/clips/{$clip->id}/render-status")->assertOk();
        $this->api($plain)->postJson('/api/v1/projects', ['youtube_url' => 'https://youtu.be/abcdefghijk'])->assertCreated();
        $this->api($plain)->postJson("/api/v1/clips/{$clip->id}/youtube", [])->assertForbidden()
            ->assertJsonPath('required_ability', 'publish');

        [$publishOnly] = ApiToken::issue($this->user, 'p', ['publish']);
        $this->assertSame(['read', 'publish'], $publishOnly->abilities);
    }

    public function test_can_grants_read_to_render_or_publish_even_for_stored_data_without_it(): void
    {
        $legacy = fn (array $abilities) => (new ApiToken)->forceFill(['abilities' => $abilities]);

        $this->assertTrue($legacy(['render'])->can('read'));
        $this->assertTrue($legacy(['publish'])->can('read'));
        $this->assertFalse($legacy([])->can('read'));
        $this->assertFalse($legacy(['read'])->can('render'));
        $this->assertFalse($legacy(['render'])->can('publish'));
        $this->assertFalse($legacy(['read', 'render'])->can('publish'));
    }

    public function test_read_only_token_still_cannot_render(): void
    {
        [$ro, $plain] = ApiToken::issue($this->user, 'ro', ['read']);
        $clip = $this->clip($this->user);

        $this->assertSame(['read'], $ro->abilities);
        $this->api($plain)->postJson("/api/v1/clips/{$clip->id}/render")->assertForbidden()
            ->assertJsonPath('required_ability', 'render');
        Queue::assertNothingPushed();
    }

    public function test_mcp_lists_read_tools_for_a_render_only_token(): void
    {
        [, $plain] = ApiToken::issue($this->user, 'r', ['render']);
        $names = collect($this->api($plain)->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->json('result.tools'))->pluck('name')->all();

        $this->assertEqualsCanonicalizing(
            ['create_project', 'get_status', 'list_clips', 'render_clip', 'get_render_status', 'list_publish_accounts'],
            $names,
        );
    }

    public function test_publish_ability_gets_past_the_gate(): void
    {
        [, $pub] = ApiToken::issue($this->user, 'pub', ['publish']);
        $clip = $this->clip($this->user, 'done');

        // 422 = validation ran, so the ability check let it through
        $this->api($pub)->postJson("/api/v1/clips/{$clip->id}/youtube", [])->assertStatus(422);
    }

    public function test_mcp_lists_only_tools_the_token_may_call(): void
    {
        [, $ro] = ApiToken::issue($this->user, 'ro', ['read']);
        $names = fn (string $tok) => collect($this->api($tok)->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->json('result.tools'))->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['get_status', 'list_clips', 'get_render_status', 'list_publish_accounts'], $names($ro));
        $this->assertContains('publish', $names($this->token));
        $this->assertContains('create_project', $names($this->token));
    }

    public function test_mcp_tool_without_the_ability_is_refused(): void
    {
        [, $ro] = ApiToken::issue($this->user, 'ro', ['read']);
        $clip = $this->clip($this->user);

        $res = $this->api($ro)->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'render_clip', 'arguments' => ['clip_id' => $clip->id]],
        ])->assertJsonPath('result.isError', true);

        $text = json_decode($res->json('result.content.0.text'), true);
        $this->assertSame(403, $text['http_status']);
        $this->assertStringContainsString('render', $text['error']);
        Queue::assertNothingPushed();
    }

    public function test_mcp_publish_schema_has_a_privacy_level_enum(): void
    {
        $publish = collect($this->rpc('tools/list')->json('result.tools'))->firstWhere('name', 'publish');

        $this->assertSame(
            ['PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'FOLLOWER_OF_CREATOR', 'SELF_ONLY'],
            $publish['inputSchema']['properties']['privacy_level']['enum'],
        );
    }

    public function test_web_created_tokens_default_to_read_and_render(): void
    {
        $this->actingAs($this->user);

        $this->postJson('/api/tokens', ['name' => 'a'])->assertCreated()->assertJsonPath('abilities', ['read', 'render']);
        $this->postJson('/api/tokens', ['name' => 'b', 'abilities' => ['publish', 'read']])->assertCreated()
            ->assertJsonPath('abilities', ['read', 'publish']);
        $this->getJson('/api/tokens')->assertJsonFragment(['abilities' => ['read', 'publish']]);
    }

    public function test_web_token_abilities_are_validated(): void
    {
        $this->actingAs($this->user);

        $this->postJson('/api/tokens', ['name' => 'a', 'abilities' => []])->assertUnprocessable();
        $this->postJson('/api/tokens', ['name' => 'a', 'abilities' => ['admin']])->assertUnprocessable();
    }

    public function test_web_token_with_only_render_is_stored_with_read(): void
    {
        $this->actingAs($this->user);

        $this->postJson('/api/tokens', ['name' => 'a', 'abilities' => ['render']])->assertCreated()
            ->assertJsonPath('abilities', ['read', 'render']);
    }

    public function test_settings_form_pins_read_on(): void
    {
        $html = $this->actingAs($this->user)->get('/settings/api')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input type="checkbox" checked disabled \/>\s*<span>Baca/', $html);
    }

    public function test_token_cap_is_enforced_for_web_and_cli(): void
    {
        $this->actingAs($this->user);
        for ($i = 1; $i < ApiToken::MAX_PER_USER; $i++) { // setUp made one
            $this->postJson('/api/tokens', ['name' => "t{$i}"])->assertCreated();
        }

        $this->postJson('/api/tokens', ['name' => 'over'])->assertUnprocessable();
        $this->assertSame(1, Artisan::call('clipper:token', ['email' => $this->user->email]));
        $this->assertSame(ApiToken::MAX_PER_USER, ApiToken::where('user_id', $this->user->id)->count());
    }

    public function test_token_cap_check_runs_inside_a_locked_transaction(): void
    {
        $base = DB::transactionLevel(); // RefreshDatabase already holds one
        $levels = [];
        DB::listen(function ($q) use (&$levels) {
            if (str_contains($q->sql, 'count(*)') && str_contains($q->sql, 'api_tokens')) {
                $levels[] = DB::transactionLevel();
            }
        });

        ApiToken::issueLimited($this->user, 'x', ['read']);

        $this->assertSame([$base + 1], $levels);
    }

    public function test_cli_token_abilities_and_stdout(): void
    {
        $this->assertSame(0, Artisan::call('clipper:token', ['email' => $this->user->email, 'name' => 'cli']));
        $plain = strtok(Artisan::output(), "\n");
        $this->assertStringStartsWith('clp_', $plain);
        $this->assertSame(['read', 'render'], ApiToken::findByPlain($plain)->abilities);

        Artisan::call('clipper:token', ['email' => $this->user->email, 'name' => 'all', '--ability' => ['read', 'render', 'publish']]);
        $this->assertSame(['read', 'render', 'publish'], ApiToken::findByPlain(strtok(Artisan::output(), "\n"))->abilities);

        Artisan::call('clipper:token', ['email' => $this->user->email, 'name' => 'pub', '--ability' => ['publish']]);
        $this->assertSame(['read', 'publish'], ApiToken::findByPlain(strtok(Artisan::output(), "\n"))->abilities);

        $count = ApiToken::count();
        $this->assertSame(1, Artisan::call('clipper:token', ['email' => $this->user->email, '--ability' => ['root']]));
        $this->assertStringContainsString('root', Artisan::output());
        $this->assertSame($count, ApiToken::count());
    }

    public function test_cli_token_can_go_to_a_private_file_instead_of_the_terminal(): void
    {
        $file = sys_get_temp_dir().'/clp-test-'.uniqid();

        try {
            $this->assertSame(0, Artisan::call('clipper:token', ['email' => $this->user->email, '--file' => $file, '--ability' => ['read']]));
            $plain = trim(file_get_contents($file));
            $this->assertStringStartsWith('clp_', $plain);
            $this->assertStringNotContainsString($plain, Artisan::output());
            $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
            $this->assertSame(['read'], ApiToken::findByPlain($plain)->abilities);

            $count = ApiToken::count();
            $this->assertSame(1, Artisan::call('clipper:token', ['email' => $this->user->email, '--file' => $file]));
            $this->assertSame($count, ApiToken::count());
            $this->assertSame($plain, trim(file_get_contents($file)));
        } finally {
            @unlink($file);
        }
    }

    public function test_tokens_issued_before_scopes_have_every_ability(): void
    {
        $migration = require database_path('migrations/2026_11_02_000001_add_abilities_to_api_tokens_table.php');
        DB::table('api_tokens')->update(['abilities' => null]);

        $migration->down();
        $migration->up();

        $this->assertSame(['read', 'render', 'publish'], ApiToken::first()->abilities);
    }
}
