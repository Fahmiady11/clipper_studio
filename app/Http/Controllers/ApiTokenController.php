<?php

namespace App\Http\Controllers;

use App\Exceptions\TokenLimitReached;
use App\Models\ApiToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ApiTokenController extends Controller
{
    // Without an explicit choice: drive the pipeline, never publish
    private const DEFAULT_ABILITIES = ['read', 'render'];

    public function page(): View
    {
        return view('settings.api');
    }

    public function index(): JsonResponse
    {
        return response()->json(ApiToken::where('user_id', Auth::id())
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'abilities', 'last_used_at', 'created_at']));
    }

    /** The plain token is in this response only; afterwards just its hash exists. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['sometimes', 'array', 'min:1'],
            'abilities.*' => ['string', 'in:'.implode(',', ApiToken::ABILITIES)],
        ]);

        try {
            [$token, $plain] = ApiToken::issueLimited(Auth::user(), $data['name'], $data['abilities'] ?? self::DEFAULT_ABILITIES);
        } catch (TokenLimitReached $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['id' => $token->id, 'name' => $token->name, 'abilities' => $token->abilities, 'token' => $plain], 201);
    }

    public function destroy(int $tokenId): JsonResponse
    {
        ApiToken::where('user_id', Auth::id())->findOrFail($tokenId)->delete();

        return response()->json(['success' => true]);
    }
}
