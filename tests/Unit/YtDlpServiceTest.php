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

    public function test_a_section_download_asks_for_the_range_in_the_full_download_format_and_is_not_cached(): void
    {
        $dir = storage_path('framework/testing/ytdlp-section-'.getmypid());
        @mkdir($dir, 0755, true);
        $binary = $dir.'/yt-dlp';
        // Records its arguments and writes the file named after -o
        file_put_contents($binary, <<<'SH'
#!/bin/sh
printf '%s\n' "$@" > "$(dirname "$0")/args"
while [ $# -gt 0 ]; do
    if [ "$1" = "-o" ]; then echo section > "$2"; fi
    shift
done
SH);
        chmod($binary, 0755);
        $out = $dir.'/section_clip.mp4';
        $url = 'https://www.youtube.com/watch?v=sectionTest1';

        try {
            config([
                'services.ytdlp.path' => $binary,
                'services.ytdlp.cookies_from_browser' => 'chrome',
                'services.ytdlp.cookies_file' => '',
            ]);
            $ytdlp = new YtDlpService;

            $ytdlp->downloadSection($url, $out, 1197, 1263.5);

            $args = file($dir.'/args', FILE_IGNORE_NEW_LINES);
            $this->assertFileExists($out);
            $this->assertContains('*1197.000-1263.500', $args);
            $this->assertSame('--download-sections', $args[array_search('*1197.000-1263.500', $args) - 1]);
            $this->assertContains('--cookies-from-browser', $args);
            $this->assertContains('bestvideo[vcodec^=avc1][height<=1080]+bestaudio[ext=m4a]/bestvideo[height<=1080]+bestaudio/best[height<=1080]/bestvideo+bestaudio/best', $args);
            $this->assertSame($url, end($args));
            $this->assertFalse($ytdlp->isCached($url)); // a section never stands in for the whole video
        } finally {
            exec('rm -rf '.escapeshellarg($dir));
        }
    }
}
