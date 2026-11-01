<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ApiTokenController extends Controller
{
    private const MAX_PER_USER = 10;

    public function page(): View
    {
        return view('settings.api');
    }

    public function index(): JsonResponse
    {
        return response()->json(ApiToken::where('user_id', Auth::id())
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'last_used_at', 'created_at']));
    }

    /** The plain token is in this response only; afterwards just its hash exists. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        abort_if(ApiToken::where('user_id', Auth::id())->count() >= self::MAX_PER_USER, 422, 'Maksimal '.self::MAX_PER_USER.' token. Hapus yang tidak dipakai dulu.');

        [$token, $plain] = ApiToken::issue(Auth::user(), $data['name']);

        return response()->json(['id' => $token->id, 'name' => $token->name, 'token' => $plain], 201);
    }

    public function destroy(int $tokenId): JsonResponse
    {
        ApiToken::where('user_id', Auth::id())->findOrFail($tokenId)->delete();

        return response()->json(['success' => true]);
    }
}
