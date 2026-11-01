<?php

namespace App\Http\Controllers;

use App\Exceptions\QuotaExceeded;
use App\Http\Controllers\Concerns\EnsuresQueueWorker;
use App\Models\GeneratedClip;
use App\Models\HookTest;
use App\Models\TiktokAccount;
use App\Services\HookTestService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class HookTestController extends Controller
{
    use EnsuresQueueWorker;

    // Same floor as the calendar: the scheduler ticks once a minute
    private const MIN_LEAD_MINUTES = 2;

    public function __construct(private HookTestService $tests) {}

    public function show(string $clipId): JsonResponse
    {
        $test = $this->ownedClip($clipId)->hookTest;

        return response()->json(['test' => $test ? $this->tests->present($test) : null]);
    }

    /** Write 2 or 3 hooks for a finished or pending clip and render a variant of each. */
    public function store(Request $request, string $clipId): JsonResponse
    {
        $data = $request->validate(['count' => ['required', 'integer', 'between:'.HookTestService::MIN_VARIANTS.','.HookTestService::MAX_VARIANTS]]);
        $clip = $this->ownedClip($clipId);
        abort_if($clip->variant_of_id !== null, 422, 'Varian tidak bisa dibuatkan varian lagi.');
        abort_if($clip->hookTest()->exists(), 409, 'Clip ini sudah punya tes hook. Hapus dulu kalau mau membuat ulang.');
        abort_if($clip->review_status === 'rejected', 422, 'Clip ini sudah ditolak.');

        try {
            $test = $this->tests->createVariants(Auth::user(), $clip, (int) $data['count']);
        } catch (QuotaExceeded $e) {
            return response()->json(['message' => $e->getMessage()], 402);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $this->ensureQueueWorkerRunning();

        return response()->json(['test' => $this->tests->present($test)], 201);
    }

    /** Schedule the rendered variants, one per gap, to one TikTok account. */
    public function publish(Request $request, string $clipId): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer'],
            'start_at' => ['nullable', 'date'],
            'gap_hours' => ['nullable', 'integer', 'between:6,72'],
        ]);
        $test = $this->ownedClip($clipId)->hookTest ?? abort(404, 'Clip ini belum punya tes hook.');
        $account = TiktokAccount::where('user_id', Auth::id())->findOrFail($data['account_id']);

        $start = isset($data['start_at']) ? CarbonImmutable::parse($data['start_at'])->utc() : now()->addMinutes(5)->toImmutable();
        if ($start->lt(now()->addMinutes(self::MIN_LEAD_MINUTES))) {
            throw ValidationException::withMessages(['start_at' => 'Waktu mulai minimal '.self::MIN_LEAD_MINUTES.' menit dari sekarang.']);
        }

        try {
            $this->tests->schedule($test, $account, $start, (int) ($data['gap_hours'] ?? 24));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['test' => $this->tests->present($test->refresh())]);
    }

    public function destroy(string $clipId): JsonResponse
    {
        $test = $this->ownedClip($clipId)->hookTest ?? abort(404, 'Clip ini belum punya tes hook.');
        abort_if($test->status === HookTest::STATUS_TESTING, 409, 'Tes sedang berjalan dan tidak bisa dihapus.');
        abort_if($test->clip->variants()->where('status', 'processing')->exists(), 409, 'Varian masih dirender. Tunggu selesai atau batalkan dulu.');

        $this->tests->discard($test);

        return response()->json(['success' => true]);
    }

    private function ownedClip(string $clipId): GeneratedClip
    {
        $clip = GeneratedClip::with('clipProject.hookSetting', 'clipProject.transcript')->findOrFail($clipId);
        abort_if($clip->clipProject->user_id !== Auth::id(), 403);

        return $clip;
    }
}
