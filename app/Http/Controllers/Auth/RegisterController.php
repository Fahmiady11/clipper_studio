<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\InviteCode;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class RegisterController extends Controller
{
    /** Sign-up is open for the first account, then only when REGISTRATION_ENABLED=true. */
    public static function open(): bool
    {
        if (config('auth.registration_enabled')) {
            return true;
        }
        try {
            return ! User::query()->exists() || InviteCode::query()->exists();
        } catch (QueryException) {
            return false; // database not migrated yet: keep sign-up closed rather than error
        }
    }

    /** Closed beta: once the first account exists, sign-up needs an invite code unless registration is fully open. */
    public static function needsInvite(): bool
    {
        if (config('auth.registration_enabled')) {
            return false;
        }
        try {
            return User::query()->exists();
        } catch (QueryException) {
            return false;
        }
    }

    public function show(): View|RedirectResponse
    {
        if (! self::open()) {
            return redirect()->route('login')->with('status', 'Pendaftaran akun baru ditutup. Hubungi admin.');
        }

        return view('auth.register', ['needsInvite' => self::needsInvite(), 'invite' => request('invite')]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(self::open(), 403, 'Pendaftaran akun baru ditutup.');

        $needsInvite = self::needsInvite();
        $data = $request->validate([
            'invite' => [$needsInvite ? 'required' : 'nullable', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        // The very first account is the main admin; everyone after waits for its approval
        $first = ! User::query()->exists();

        $invite = null;
        if ($needsInvite) {
            $invite = InviteCode::redeem($data['invite']);
            if (! $invite) {
                return back()->withInput($request->except('password', 'password_confirmation'))
                    ->withErrors(['invite' => 'Kode undangan tidak valid, sudah habis, atau kedaluwarsa.']);
            }
        }

        $user = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);
        // A valid invite is the approval: the admin vetted the person when handing it out
        $user->forceFill([
            'is_admin' => $first,
            'approved_at' => $first || $invite ? now() : null,
            'invite_code_id' => $invite?->id,
        ])->save();

        event(new Registered($user));

        if (! $first && ! $invite) {
            return redirect()->route('login')
                ->with('status', 'Akun berhasil dibuat. Tunggu persetujuan admin sebelum bisa masuk.');
        }

        Auth::login($user);
        $request->session()->regenerate(); // new session id for the signed-in user (no fixation)

        return redirect()->route('home');
    }
}
