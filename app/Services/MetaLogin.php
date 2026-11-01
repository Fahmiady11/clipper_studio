<?php

namespace App\Services;

use App\Models\MetaAccount;
use App\Models\MetaTarget;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Facebook Login for Business: the code becomes a long-lived user token
 * (about 60 days), and the Pages the user picks keep their own Page token,
 * which does not expire while the user stays an admin and keeps the app.
 */
class MetaLogin
{
    // Publishing a Reel needs a Page token of someone who can create content there
    public const PUBLISH_TASK = 'CREATE_CONTENT';

    public const REQUIRED_SCOPES = ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts'];

    private const MAX_PAGE_REQUESTS = 5;

    public function __construct(private MetaGraph $graph) {}

    public function isConfigured(): bool
    {
        return $this->graph->isConfigured();
    }

    public function authorizeUrl(string $state): string
    {
        $configId = config('services.facebook.config_id');

        return 'https://www.facebook.com/'.$this->graph->version().'/dialog/oauth?'.http_build_query([
            'client_id' => config('services.facebook.app_id'),
            'redirect_uri' => config('services.facebook.redirect_uri'),
            'state' => $state,
            'response_type' => 'code',
        ] + ($configId ? ['config_id' => $configId] : ['scope' => config('services.facebook.scopes')]));
    }

    /** Exchange the OAuth code and store/refresh the login. Pages are picked afterwards. */
    public function connect(int $userId, string $code): MetaAccount
    {
        $short = $this->graph->token([
            'code' => $code,
            'redirect_uri' => config('services.facebook.redirect_uri'),
        ]);
        $long = $this->graph->token([
            'grant_type' => 'fb_exchange_token',
            'fb_exchange_token' => $short['access_token'] ?? throw new RuntimeException('Facebook tidak mengirim access token.'),
        ]);
        $token = $long['access_token'] ?? throw new RuntimeException('Facebook tidak mengirim token jangka panjang.');

        $me = $this->graph->get('me', $token, ['fields' => 'id,name'], 'profil');
        $granted = collect($this->graph->get('me/permissions', $token, [], 'izin')['data'] ?? [])
            ->where('status', 'granted')->pluck('permission')->implode(',');

        return MetaAccount::updateOrCreate(
            ['user_id' => $userId, 'provider' => 'facebook', 'provider_user_id' => (string) $me['id']],
            [
                'name' => $me['name'] ?? null,
                'access_token' => $token,
                'token_expires_at' => isset($long['expires_in']) ? now()->addSeconds((int) $long['expires_in']) : null,
                'scopes' => $granted,
            ]
        );
    }

    /** @return list<string> required permissions the user unticked in the dialog */
    public function missingScopes(MetaAccount $account): array
    {
        return array_values(array_diff(self::REQUIRED_SCOPES, $account->grantedScopes()));
    }

    /**
     * The Pages this login can see, fresh from Meta, with whether each is
     * already connected and whether a Reel can be published there.
     *
     * @return list<array{id: string, name: ?string, picture_url: ?string, can_publish: bool, connected: bool}>
     */
    public function pages(MetaAccount $account): array
    {
        $connected = MetaTarget::where('meta_account_id', $account->id)->where('platform', 'facebook')->pluck('external_id')->all();

        return array_map(fn (array $page) => [
            'id' => (string) $page['id'],
            'name' => $page['name'] ?? null,
            'picture_url' => $page['picture']['data']['url'] ?? null,
            'can_publish' => in_array(self::PUBLISH_TASK, $page['tasks'] ?? [], true),
            'connected' => in_array((string) $page['id'], $connected, true),
        ], $this->rawPages($account));
    }

    /**
     * Keep exactly the chosen Pages of this login, each with its Page token.
     * A Page connected through another login of the same user moves here.
     *
     * @param  list<string>  $pageIds
     * @return int how many Pages are connected now
     */
    public function savePages(MetaAccount $account, array $pageIds): int
    {
        $pages = collect($this->rawPages($account))->keyBy(fn ($p) => (string) $p['id']);
        $chosen = $pages->only($pageIds);
        if ($chosen->count() !== count(array_unique($pageIds))) {
            throw new RuntimeException('Ada Page yang tidak bisa diakses akun Facebook ini. Muat ulang daftar Page.');
        }
        if ($blocked = $chosen->first(fn ($p) => ! in_array(self::PUBLISH_TASK, $p['tasks'] ?? [], true))) {
            throw new RuntimeException("Kamu tidak punya izin membuat konten di Page {$blocked['name']}.");
        }

        DB::transaction(function () use ($account, $chosen) {
            MetaTarget::where('meta_account_id', $account->id)->where('platform', 'facebook')
                ->whereNotIn('external_id', $chosen->keys()->all())->delete();

            foreach ($chosen as $id => $page) {
                MetaTarget::updateOrCreate(
                    ['user_id' => $account->user_id, 'platform' => 'facebook', 'external_id' => $id],
                    [
                        'meta_account_id' => $account->id,
                        'name' => $page['name'] ?? null,
                        'picture_url' => $page['picture']['data']['url'] ?? null,
                        'access_token' => $page['access_token'] ?? throw new RuntimeException('Facebook tidak mengirim token Page.'),
                        'details' => ['tasks' => $page['tasks'] ?? []],
                    ]
                );
            }
        });

        return $chosen->count();
    }

    /** /me/accounts, following the cursor a few pages deep. */
    private function rawPages(MetaAccount $account): array
    {
        $pages = [];
        $after = null;
        for ($i = 0; $i < self::MAX_PAGE_REQUESTS; $i++) {
            $res = $this->graph->get('me/accounts', $account->access_token, array_filter([
                'fields' => 'id,name,access_token,tasks,picture{url}',
                'limit' => 100,
                'after' => $after,
            ]), 'daftar Page');
            array_push($pages, ...($res['data'] ?? []));
            $after = $res['paging']['cursors']['after'] ?? null;
            if (empty($res['paging']['next']) || ! $after) {
                break;
            }
        }

        return $pages;
    }
}
