<?php

namespace Tests\Unit;

use App\Services\YtDlpService;
use Tests\TestCase;

class YtDlpServiceTest extends TestCase
{
    public function test_keyword_listing_uses_supported_youtube_search_prefix(): void
    {
        $binary = storage_path('framework/testing/fake-ytdlp-'.getmypid());
        @mkdir(dirname($binary), 0755, true);
        file_put_contents($binary, <<<'SH'
#!/bin/sh
for argument in "$@"; do
    case "$argument" in
        ytsearch3:cats)
            echo '{"id":"video-1","title":"Cats","channel":"Channel","duration":42}'
            exit 0
            ;;
        ytsearchdate*)
            echo 'Unsupported url scheme: ytsearchdate' >&2
            exit 2
            ;;
    esac
done
echo 'Expected ytsearch3:cats argument' >&2
exit 3
SH);
        chmod($binary, 0755);

        try {
            config([
                'services.ytdlp.path' => $binary,
                'services.ytdlp.cookies_from_browser' => '',
                'services.ytdlp.cookies_file' => '',
            ]);

            $videos = (new YtDlpService)->listVideos('keyword', 'cats', 3);

            $this->assertSame('video-1', $videos[0]['id']);
        } finally {
            @unlink($binary);
        }
    }
}
