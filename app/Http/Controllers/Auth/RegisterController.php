<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
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
            return !User::query()->exists();
        } catch (\Illuminate\Database\QueryException) {
            return false; // database not migrated yet: keep sign-up closed rather than error
        }
    }

    public function show(): View|RedirectResponse
    {
        if (!self::open()) {
            return redirect()->route('login')->with('status', 'Pendaftaran akun baru ditutup. Hubungi admin.');
        }

        return view('auth.register');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(self::open(), 403, 'Pendaftaran akun baru ditutup.');

        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        event(new Registered($user));
        Auth::login($user);
        $request->session()->regenerate(); // new session id for the signed-in user (no fixation)

        return redirect()->route('home');
    }
}
