<?php

namespace App\Services;

use App\Models\ClipProject;
use App\Models\GeneratedClip;
use App\Models\ScheduledPost;
use App\Models\User;
use App\Models\VideoSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Deletes an account for good, right away. Work in flight is stopped first so
 * nothing gets posted while the platforms are being told, then the row goes
 * through Eloquent: FK cascades drop the data and User's deleted hook drops the
 * files. Jobs still in the queue find their clip gone and exit quietly.
 */
class AccountDeletion
{
    public function __construct(private PlatformRevoker $revoker) {}

    /** Without an admin nobody can approve sign-ups, so the last one has to stay. */
    public function isLastAdmin(User $user): bool
    {
        return $user->is_admin && ! User::where('is_admin', true)->whereKeyNot($user->id)->exists();
    }

    public function delete(User $user): void
    {
        $this->stopWork($user);
        $revoked = $this->revoker->revokeAll($user);

        $userId = $user->id;
        $user->delete();

        // Sessions on other devices: the database driver keeps them with the user id
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $userId)->delete();
        }

        Log::channel('clipper_api')->info('Account deleted by its owner', ['user_id' => $userId] + $revoked);
    }

    /** Pending posts, autopilot and running analyses/renders: none of it may start or post anything now. */
    private function stopWork(User $user): void
    {
        ScheduledPost::where('user_id', $user->id)->where('status', ScheduledPost::STATUS_SCHEDULED)->delete();
        VideoSource::where('user_id', $user->id)->update(['enabled' => false]);

        // The jobs poll cancelled_at and kill yt-dlp/ffmpeg mid-run
        $projects = ClipProject::select('id')->where('user_id', $user->id);
        GeneratedClip::whereIn('clip_project_id', $projects)->whereNull('cancelled_at')->update(['cancelled_at' => now()]);
        ClipProject::where('user_id', $user->id)->whereNull('cancelled_at')->update(['cancelled_at' => now()]);
    }
}
