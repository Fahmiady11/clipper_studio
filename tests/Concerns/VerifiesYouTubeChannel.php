<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Models\YoutubeAccount;
use Illuminate\Support\Facades\Http;

trait VerifiesYouTubeChannel
{
    private string $lookupChannelId = 'UCowned000000000000000';

    private bool $lookupsFaked = false;

    /** A channel the user proved with OAuth, as stored at connect time. */
    protected function ownChannel(User $user, string $channelId = 'UCowned000000000000000'): YoutubeAccount
    {
        return YoutubeAccount::create([
            'user_id' => $user->id,
            'channel_id' => $channelId,
            'title' => 'Channel Sendiri',
            'access_token' => 'at',
            'refresh_token' => 'rt',
            'access_expires_at' => now()->addHour(),
        ]);
    }

    /** Google answers video and channel lookups with $channelId, nothing else. */
    protected function fakeChannelLookups(string $channelId = 'UCowned000000000000000'): void
    {
        $this->lookupChannelId = $channelId;
        if ($this->lookupsFaked) {
            return; // the stub reads the property, so switching channels is instant
        }
        $this->lookupsFaked = true;

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_starts_with($url, 'https://www.googleapis.com/youtube/v3/videos')) {
                return Http::response(['items' => [['id' => 'dQw4w9WgXcQ', 'snippet' => ['channelId' => $this->lookupChannelId]]]]);
            }
            if (str_starts_with($url, 'https://www.googleapis.com/youtube/v3/channels')) {
                return Http::response(['items' => [['id' => $this->lookupChannelId]]]);
            }

            return Http::response(['error' => 'unexpected call '.$url], 500);
        });
    }

    /** A user whose channel is verified, with Google lookups faked to match. */
    protected function verifiedUser(string $channelId = 'UCowned000000000000000'): User
    {
        $this->fakeChannelLookups($channelId);
        $user = User::factory()->create();
        $this->ownChannel($user, $channelId);

        return $user;
    }
}
