<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/** The main admin decides who may use the app. */
class UserApprovalController extends Controller
{
    public function index(): View
    {
        return view('admin.users', [
            'pending' => User::whereNull('approved_at')->oldest()->get(),
            'approved' => User::whereNotNull('approved_at')->orderByDesc('is_admin')->oldest('approved_at')->get(),
        ]);
    }

    /** Admin-made accounts are approved right away; the admin passes the password on. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('create', [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = new User($data);
        $user->forceFill(['approved_at' => now()])->save();
        Log::channel('clipper_api')->info('User created by admin', ['admin_id' => Auth::id(), 'user_id' => $user->id]);

        return back()->with('status', "Akun {$user->email} dibuat dan bisa langsung masuk.");
    }

    public function approve(User $user): RedirectResponse
    {
        $user->forceFill(['approved_at' => now()])->save();
        Log::channel('clipper_api')->info('User approved', ['admin_id' => Auth::id(), 'user_id' => $user->id]);

        return back()->with('status', "{$user->name} sekarang bisa masuk.");
    }

    /** Take access away; open sessions end on their next request (EnsureUserIsApproved). */
    public function revoke(User $user): RedirectResponse
    {
        abort_if($user->is_admin, 422, 'Akses admin tidak bisa dicabut.');

        $user->forceFill(['approved_at' => null])->save();
        Log::channel('clipper_api')->info('User access revoked', ['admin_id' => Auth::id(), 'user_id' => $user->id]);

        return back()->with('status', "Akses {$user->name} dicabut.");
    }

    /** Turn down a sign-up. Only waiting accounts can be deleted here: they have no data yet. */
    public function reject(User $user): RedirectResponse
    {
        abort_if($user->isApproved(), 422, 'Hanya pendaftaran yang belum disetujui yang bisa ditolak.');

        $user->delete();
        Log::channel('clipper_api')->info('Sign-up rejected', ['admin_id' => Auth::id(), 'email' => $user->email]);

        return back()->with('status', "Pendaftaran {$user->email} ditolak.");
    }
}
