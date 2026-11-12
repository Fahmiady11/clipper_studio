<?php

namespace Tests\Unit;

use App\Services\ScheduledPublisher;
use App\Services\TikTokService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TikTokPostInfoTest extends TestCase
{
    public static function privacyLevels(): array
    {
        return array_combine(TikTokService::PRIVACY_LEVELS, array_map(fn ($level) => [$level], TikTokService::PRIVACY_LEVELS));
    }

    #[DataProvider('privacyLevels')]
    public function test_each_privacy_level_is_posted_as_chosen(string $level): void
    {
        $info = ScheduledPublisher::tiktokPostInfo(['title' => 'Cap', 'privacy_level' => $level]);

        $this->assertSame($level, $info['privacy_level']);
    }

    public function test_missing_or_unknown_privacy_falls_back_to_self_only(): void
    {
        $this->assertSame('SELF_ONLY', ScheduledPublisher::tiktokPostInfo([])['privacy_level']);
        $this->assertSame('SELF_ONLY', ScheduledPublisher::tiktokPostInfo(['privacy_level' => 'EVERYONE'])['privacy_level']);
        $this->assertSame('SELF_ONLY', ScheduledPublisher::tiktokPostInfo(['privacy_level' => null])['privacy_level']);
    }

    public function test_flags_follow_the_options(): void
    {
        $this->assertSame([
            'title' => 'Cap #fyp', 'privacy_level' => 'PUBLIC_TO_EVERYONE', 'disable_comment' => false, 'disable_duet' => true,
            'disable_stitch' => false, 'brand_organic_toggle' => true, 'brand_content_toggle' => false,
        ], ScheduledPublisher::tiktokPostInfo([
            'title' => 'Cap #fyp', 'privacy_level' => 'PUBLIC_TO_EVERYONE', 'allow_comment' => true, 'allow_duet' => false,
            'allow_stitch' => true, 'brand_organic_toggle' => true,
        ]));

        $this->assertSame([
            'title' => '', 'privacy_level' => 'SELF_ONLY', 'disable_comment' => true, 'disable_duet' => true,
            'disable_stitch' => true, 'brand_organic_toggle' => false, 'brand_content_toggle' => false,
        ], ScheduledPublisher::tiktokPostInfo(['mode' => 'direct']));

        $this->assertTrue(ScheduledPublisher::tiktokPostInfo(['privacy_level' => 'MUTUAL_FOLLOW_FRIENDS', 'brand_content_toggle' => true])['brand_content_toggle']);
    }
}
