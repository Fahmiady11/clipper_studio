<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
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
            return ! User::query()->exists();
        } catch (QueryException) {
            return false; // database not migrated yet: keep sign-up closed rather than error
        }
    }

    public function show(): View|RedirectResponse
    {
        if (! self::open()) {
            return redirect()->route('login')->with('status', 'Pendaftaran akun baru ditutup. Hubungi admin.');
        }

        return view('auth.register');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(self::open(), 403, 'Pendaftaran akun baru ditutup.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        // The very first account is the main admin; everyone after waits for its approval
        $first = ! User::query()->exists();

        $user = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);
        $user->forceFill(['is_admin' => $first, 'approved_at' => $first ? now() : null])->save();

        event(new Registered($user));

        if (! $first) {
            return redirect()->route('login')
                ->with('status', 'Akun berhasil dibuat. Tunggu persetujuan admin sebelum bisa masuk.');
        }

        Auth::login($user);
        $request->session()->regenerate(); // new session id for the signed-in user (no fixation)

        return redirect()->route('home');
    }
}
