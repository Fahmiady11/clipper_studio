<?php

namespace Tests\Unit;

use App\Services\GeminiService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GeminiServiceTest extends TestCase
{
    private const CLIPS = '[{"ranking":1,"start_seconds":1,"end_seconds":30,"hook_text":"Sisi *gelap* kota"}]';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.gemini.key'          => 'k',
            'services.gemini.models'       => ['model-a', 'model-b', 'model-c'],
            'services.gemini.retry_pauses' => [0, 0],
            'services.llm_gateway'         => ['url' => null, 'key' => null, 'model' => null],
        ]);
    }

    private function ok(string $json): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => $json]]]]]];
    }

    private function analyze(): array
    {
        return app(GeminiService::class)->analyzeWithTranscript(['language' => 'id', 'segments' => [['start' => 0, 'end' => 5, 'text' => 'halo']]], 1, 'reframe');
    }

    public function test_spent_daily_quota_skips_to_next_model_and_overload_is_retried(): void
    {
        Http::fake([
            '*model-a:*' => Http::response(['error' => ['code' => 429, 'message' => 'Quota exceeded for metric: generativelanguage.googleapis.com/generate_content_free_tier_requests, limit: 20']], 429),
            '*model-b:*' => Http::sequence()->push(['error' => ['code' => 503]], 503)->push($this->ok(self::CLIPS)),
        ]);

        $clips = $this->analyze();

        $this->assertSame('Sisi *gelap* kota', $clips[0]['hook_text']);
        // The API key travels in a header, never in the URL (URLs leak into logs)
        Http::assertSent(fn($r) => $r->hasHeader('x-goog-api-key', 'k') && !str_contains($r->url(), 'key='));
        Http::assertSentCount(3); // a once (no waiting on a daily quota), b twice
    }

    public function test_gateway_is_tried_first_with_openai_format(): void
    {
        config(['services.llm_gateway' => ['url' => 'http://gw.test/v1/', 'key' => 'secret', 'model' => 'gemini-pro']]);
        Http::fake([
            'gw.test/*' => Http::response(['choices' => [['message' => ['content' => "```json\n" . self::CLIPS . "\n```"]]]]),
        ]);

        $this->assertCount(1, $this->analyze());
        Http::assertSent(fn($r) => $r->url() === 'http://gw.test/v1/chat/completions'
            && $r->hasHeader('Authorization', 'Bearer secret')
            && $r['model'] === 'gemini-pro');
        Http::assertSentCount(1);
    }

    public function test_several_gateway_models_are_tried_in_order(): void
    {
        config(['services.llm_gateway' => ['url' => 'http://gw.test/v1', 'key' => 'k', 'model' => 'pro, flash']]);
        Http::fake([
            'gw.test/*' => function ($request) {
                return $request['model'] === 'pro'
                    ? Http::response(['error' => ['message' => 'quota']], 429)
                    : Http::response(['choices' => [['message' => ['content' => self::CLIPS]]]]);
            },
        ]);

        $this->assertCount(1, $this->analyze());
        Http::assertSent(fn($r) => $r['model'] === 'flash');
    }

    public function test_without_gemini_key_only_the_gateway_is_used(): void
    {
        config(['services.gemini.key' => '', 'services.llm_gateway' => ['url' => 'http://gw.test/v1', 'key' => 'k', 'model' => 'pro']]);
        Http::fake(['*' => Http::response(['error' => ['message' => 'down']], 500)]);

        try {
            $this->analyze();
        } catch (RuntimeException) {
        }
        Http::assertNotSent(fn($r) => str_contains($r->url(), 'generativelanguage'));
    }

    public function test_wrapped_array_is_accepted(): void
    {
        Http::fake(['*' => Http::response($this->ok('{"clips":' . self::CLIPS . '}'))]);

        $this->assertSame(1, $this->analyze()[0]['ranking']);
    }

    public function test_all_quotas_spent_gives_an_actionable_message(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'generate_content_free_tier_requests, limit: 20']], 429)]);

        try {
            $this->analyze();
            $this->fail('expected failure');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Kuota harian Gemini habis', $e->getMessage());
            $this->assertStringContainsString('model-c: kuota harian habis', $e->getMessage());
        }
    }
}
