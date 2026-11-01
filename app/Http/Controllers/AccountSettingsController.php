<?php

namespace App\Http\Controllers;

use App\Services\AccountDeletion;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/** Settings → Akun: who is signed in, and deleting the account for good. */
class AccountSettingsController extends Controller
{
    public const CONFIRM_WORD = 'HAPUS';

    public function __construct(private AccountDeletion $deletion) {}

    public function page(): View
    {
        $user = Auth::user()->load('plan');

        return view('settings.account', [
            'user' => $user,
            'isLastAdmin' => $this->deletion->isLastAdmin($user),
            'confirmWord' => self::CONFIRM_WORD,
        ]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        $request->validateWithBag('deleteAccount', [
            'password' => ['required', 'string', 'current_password'],
            'confirmation' => ['required', 'string', function (string $attribute, string $value, Closure $fail) {
                // Phone keyboards capitalise only the first letter; the word matters, not its case
                if (mb_strtoupper(trim($value)) !== self::CONFIRM_WORD) {
                    $fail('Ketik '.self::CONFIRM_WORD.' untuk konfirmasi.');
                }
            }],
        ], [
            'password.required' => 'Masukkan password kamu.',
            'password.current_password' => 'Password salah.',
            'confirmation.required' => 'Ketik '.self::CONFIRM_WORD.' untuk konfirmasi.',
        ]);

        if ($this->deletion->isLastAdmin($user)) {
            return back()->withErrors(['account' => 'Kamu satu-satunya admin, jadi akun ini tidak bisa dihapus.'], 'deleteAccount');
        }

        Auth::guard('web')->logout();
        $this->deletion->delete($user);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Akun kamu dan semua datanya sudah dihapus.');
    }
}
