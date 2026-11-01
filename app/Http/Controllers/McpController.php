<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ThrottleAgent;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * MCP server over Streamable HTTP (POST, JSON replies). Every tool is a call
 * to the REST API as the same token holder, so quota, ownership checks and
 * validation behave exactly as for direct API clients.
 */
class McpController extends Controller
{
    private const PROTOCOLS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    // Inner calls skip the agent throttle (see ThrottleAgent), so cap what one request can fan out to
    private const MAX_BATCH = 20;

    public function handle(Request $request): JsonResponse|Response
    {
        $payload = $request->json()->all();
        if ($payload === []) {
            return $this->error(null, -32700, 'Parse error');
        }

        if (array_is_list($payload)) {
            if (count($payload) > self::MAX_BATCH) {
                return $this->error(null, -32600, 'Batch terlalu besar (maksimal '.self::MAX_BATCH.' pesan).');
            }
            $replies = array_values(array_filter(array_map(fn ($m) => $this->message($request, $m), $payload)));

            return $replies ? response()->json($replies) : response()->noContent(202);
        }

        $reply = $this->message($request, $payload);

        return $reply ? response()->json($reply) : response()->noContent(202);
    }

    private function message(Request $request, mixed $msg): ?array
    {
        if (! is_array($msg) || ($msg['jsonrpc'] ?? null) !== '2.0' || ! isset($msg['method'])) {
            return $this->errorBody($msg['id'] ?? null, -32600, 'Invalid Request');
        }
        // Notifications and client responses carry no id: nothing to answer
        if (! array_key_exists('id', $msg)) {
            return null;
        }

        $id = $msg['id'];
        $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];

        return match ($msg['method']) {
            'initialize' => $this->result($id, [
                'protocolVersion' => in_array($params['protocolVersion'] ?? null, self::PROTOCOLS, true) ? $params['protocolVersion'] : self::PROTOCOLS[0],
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'clipper', 'version' => '1.0.0'],
                'instructions' => 'Clipper turns a YouTube video into short vertical clips. create_project, poll get_status until done, list_clips, render_clip, poll get_render_status, then publish.',
            ]),
            'ping' => $this->result($id, new \stdClass),
            'tools/list' => $this->result($id, ['tools' => $this->visibleTools($request)]),
            'tools/call' => $this->callTool($request, $id, $params),
            default => $this->errorBody($id, -32601, 'Method not found'),
        };
    }

    /** Only the tools this token may call; calling a hidden one still fails with 403 from the route. */
    private function visibleTools(Request $request): array
    {
        $token = $request->attributes->get('api_token');

        return array_values(array_map(
            fn ($t) => ['name' => $t['name'], 'description' => $t['description'], 'inputSchema' => $t['inputSchema']],
            array_filter(self::tools(), fn ($t) => $token?->can($t['ability'])),
        ));
    }

    private function callTool(Request $request, mixed $id, array $params): array
    {
        $tool = collect(self::tools())->firstWhere('name', $params['name'] ?? null);
        if (! $tool) {
            return $this->errorBody($id, -32602, 'Unknown tool: '.($params['name'] ?? ''));
        }

        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        foreach ($tool['inputSchema']['required'] ?? [] as $key) {
            if (! isset($args[$key]) || $args[$key] === '') {
                return $this->result($id, $this->toolText("Argumen wajib: {$key}", true));
            }
        }

        [$method, $uri, $body] = $tool['route']($args);
        $response = $this->dispatch($request, $method, $uri, $body);
        $data = json_decode($response->getContent(), true);
        $ok = $response->getStatusCode() < 400;

        if (! $ok) {
            $message = is_array($data) ? ($data['message'] ?? 'Permintaan gagal.') : 'Permintaan gagal.';
            if (is_array($data) && ! empty($data['errors'])) {
                $message .= ' '.json_encode($data['errors'], JSON_UNESCAPED_UNICODE);
            }
            $data = ['error' => $message, 'http_status' => $response->getStatusCode()];
        }

        return $this->result($id, $this->toolText(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ! $ok));
    }

    /** Run an API route in-process as the current token holder. */
    private function dispatch(Request $parent, string $method, string $uri, array $body): SymfonyResponse
    {
        $sub = Request::create($parent->getSchemeAndHttpHost().$uri, $method, $method === 'GET' ? $body : [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$parent->bearerToken(),
            'CONTENT_TYPE' => 'application/json',
        ], $method === 'GET' ? null : json_encode($body));

        $sub->attributes->set(ThrottleAgent::INTERNAL, true);

        try {
            return app(Kernel::class)->handle($sub);
        } finally {
            app()->instance('request', $parent);
        }
    }

    private function toolText(string $text, bool $isError = false): array
    {
        return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => $isError];
    }

    private function result(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function errorBody(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    private function error(mixed $id, int $code, string $message): JsonResponse
    {
        return response()->json($this->errorBody($id, $code, $message));
    }

    private static function tools(): array
    {
        $str = fn (string $desc) => ['type' => 'string', 'description' => $desc];

        return [
            [
                'name' => 'create_project',
                'ability' => 'render',
                'description' => 'Start analysing a YouTube video; the AI picks the best moments as clips. Returns project_id. Poll get_status until status is "done".',
                'inputSchema' => ['type' => 'object', 'required' => ['youtube_url'], 'properties' => [
                    'youtube_url' => $str('YouTube watch, youtu.be or Shorts link'),
                    'clip_count' => ['type' => 'integer', 'enum' => [1, 3, 5], 'description' => 'How many clips to find (default 3)'],
                    'layout_type' => ['type' => 'string', 'enum' => ['reframe', 'gaussian', 'gameplay'], 'description' => 'Vertical reframe, blurred-background, or gameplay (speaker on top, looping footage below; only when the server has footage). Default reframe'],
                    'duration_mode' => ['type' => 'string', 'enum' => ['auto', 'manual']],
                    'min_duration' => ['type' => 'integer', 'description' => 'Seconds, with duration_mode=manual'],
                    'max_duration' => ['type' => 'integer', 'description' => 'Seconds, with duration_mode=manual'],
                ]],
                'route' => fn ($a) => ['POST', '/api/v1/projects', $a],
            ],
            [
                'name' => 'get_status',
                'ability' => 'read',
                'description' => 'Analysis progress of a project (status draft/processing/done/failed) with its clips and their render status.',
                'inputSchema' => ['type' => 'object', 'required' => ['project_id'], 'properties' => ['project_id' => $str('Id from create_project')]],
                'route' => fn ($a) => ['GET', '/api/v1/projects/'.rawurlencode($a['project_id']).'/status', []],
            ],
            [
                'name' => 'list_clips',
                'ability' => 'read',
                'description' => 'Clips found for a project, ranked, with viral score, hook, render status, download_url and publish status.',
                'inputSchema' => ['type' => 'object', 'required' => ['project_id'], 'properties' => ['project_id' => $str('Id from create_project')]],
                'route' => fn ($a) => ['GET', '/api/v1/projects/'.rawurlencode($a['project_id']).'/clips', []],
            ],
            [
                'name' => 'render_clip',
                'ability' => 'render',
                'description' => 'Render a clip to MP4 (uses render quota). Returns status "processing"; poll get_render_status. Passing hook_text re-renders with that hook.',
                'inputSchema' => ['type' => 'object', 'required' => ['clip_id'], 'properties' => [
                    'clip_id' => $str('Clip id from list_clips'),
                    'hook_text' => $str('Optional on-screen hook, max 100 chars'),
                ]],
                'route' => fn ($a) => ['POST', '/api/v1/clips/'.rawurlencode($a['clip_id']).'/render', array_intersect_key($a, ['hook_text' => 1])],
            ],
            [
                'name' => 'get_render_status',
                'ability' => 'read',
                'description' => 'Render status of one clip. When done, download_url serves the MP4 (send the same Bearer token).',
                'inputSchema' => ['type' => 'object', 'required' => ['clip_id'], 'properties' => ['clip_id' => $str('Clip id')]],
                'route' => fn ($a) => ['GET', '/api/v1/clips/'.rawurlencode($a['clip_id']).'/render-status', []],
            ],
            [
                'name' => 'list_publish_accounts',
                'ability' => 'read',
                'description' => 'Connected YouTube channels and TikTok accounts (connect them in the web app first). Gives the account_id that publish needs.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass],
                'route' => fn ($a) => ['GET', '/api/v1/publish/accounts', []],
            ],
            [
                'name' => 'publish',
                'ability' => 'publish',
                'description' => 'Upload a rendered clip to YouTube Shorts or TikTok. YouTube needs title and privacy. TikTok defaults to mode "inbox" (draft in the TikTok app). Needs the publish ability. Returns upload status.',
                'inputSchema' => ['type' => 'object', 'required' => ['clip_id', 'platform', 'account_id'], 'properties' => [
                    'clip_id' => $str('Clip id (status must be done)'),
                    'platform' => ['type' => 'string', 'enum' => ['youtube', 'tiktok']],
                    'account_id' => ['type' => 'integer', 'description' => 'From list_publish_accounts'],
                    'title' => $str('YouTube title (max 100) or TikTok caption in direct mode'),
                    'description' => $str('YouTube description'),
                    'privacy' => ['type' => 'string', 'enum' => ['public', 'unlisted', 'private'], 'description' => 'YouTube privacy'],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'YouTube tags'],
                    'mode' => ['type' => 'string', 'enum' => ['inbox', 'direct'], 'description' => 'TikTok mode'],
                    'privacy_level' => ['type' => 'string', 'enum' => ['PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'FOLLOWER_OF_CREATOR', 'SELF_ONLY'], 'description' => 'TikTok privacy level, required with mode direct. Currently forced to SELF_ONLY (TikTok app unaudited).'],
                ]],
                'route' => fn ($a) => [
                    'POST',
                    '/api/v1/clips/'.rawurlencode($a['clip_id']).'/'.($a['platform'] === 'tiktok' ? 'tiktok' : 'youtube'),
                    array_diff_key($a, ['clip_id' => 1, 'platform' => 1]),
                ],
            ],
        ];
    }
}
