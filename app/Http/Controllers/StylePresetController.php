<?php

namespace App\Http\Controllers;

use App\Models\StylePreset;
use App\Models\User;
use App\Models\VideoSource;
use App\Support\ClipSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Named clip looks: the built-ins plus the user's own, and which one new projects start from. */
class StylePresetController extends Controller
{
    private const MESSAGES = [
        'name.required' => 'Isi nama preset.',
        'name.max' => 'Nama preset maksimal 60 karakter.',
        'name.unique' => 'Nama preset ini sudah dipakai.',
    ];

    public function page(): View
    {
        return view('settings.presets');
    }

    public function index(): JsonResponse
    {
        $user = Auth::user();
        $kit = $user->brandKit;
        $default = $user->defaultStylePreset();

        return response()->json([
            'default_id' => $default?->id,
            'presets' => StylePreset::visibleTo($user->id)->get()->map(fn (StylePreset $p) => $this->payload($p, $kit))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();
        $data = $request->validate(['name' => $this->nameRules($user->id)] + StylePreset::rules(), self::MESSAGES);
        abort_if(StylePreset::where('user_id', $user->id)->count() >= StylePreset::MAX_PER_USER, 422, 'Maksimal '.StylePreset::MAX_PER_USER.' preset. Hapus yang tidak dipakai dulu.');

        $preset = StylePreset::create([
            'user_id' => $user->id,
            'name' => trim($data['name']),
            'settings' => StylePreset::normalize($data['settings']),
        ]);

        return response()->json(['preset' => $this->payload($preset, $user->brandKit)], 201);
    }

    /** Rename and/or replace the look of one of the user's own presets. */
    public function update(Request $request, int $presetId): JsonResponse
    {
        $preset = $this->ownPreset($presetId);
        $rules = ['name' => array_merge(['sometimes'], array_slice($this->nameRules(Auth::id(), $preset->id), 1))];
        $data = $request->validate($request->has('settings') ? $rules + StylePreset::rules() : $rules, self::MESSAGES);

        $preset->update(array_filter([
            'name' => isset($data['name']) ? trim($data['name']) : null,
            'settings' => isset($data['settings']) ? StylePreset::normalize($data['settings']) : null,
        ]));

        return response()->json(['preset' => $this->payload($preset, Auth::user()->brandKit)]);
    }

    public function destroy(int $presetId): JsonResponse
    {
        $preset = $this->ownPreset($presetId);
        $kit = Auth::user()->brandKit;

        DB::transaction(function () use ($preset, $kit) {
            // Sources that followed it keep the look it had, as their own settings
            VideoSource::where('style_preset_id', $preset->id)->get()->each(fn (VideoSource $s) => $s->update([
                'style_preset_id' => null,
                'settings' => ClipSettings::forAutopilot(array_replace_recursive($s->settings ?? [], $preset->look($kit))),
            ]));
            User::where('default_style_preset_id', $preset->id)->update(['default_style_preset_id' => null]);
            $preset->delete();
        });

        return response()->json(['success' => true]);
    }

    /** preset_id null = no default: projects start from the plain defaults and the brand kit. */
    public function setDefault(Request $request): JsonResponse
    {
        $data = $request->validate(['preset_id' => ['present', 'nullable', 'integer', StylePreset::existsRule(Auth::id())]]);

        $user = Auth::user();
        $user->forceFill(['default_style_preset_id' => $data['preset_id']])->save();

        return response()->json(['default_id' => $data['preset_id']]);
    }

    private function payload(StylePreset $p, $kit): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'builtin' => $p->isBuiltin(),
            'brand' => StylePreset::normalize($p->settings)['brand'],
            'summary' => $p->summary(),
            // Ready to apply: brand kit font and colours already in when the preset uses them
            'settings' => $p->look($kit),
        ];
    }

    private function nameRules(int $userId, ?int $ignoreId = null): array
    {
        return ['required', 'string', 'max:'.StylePreset::NAME_MAX,
            Rule::unique('style_presets', 'name')->where('user_id', $userId)->ignore($ignoreId)];
    }

    private function ownPreset(int $presetId): StylePreset
    {
        $preset = StylePreset::findVisible($presetId, Auth::id());
        abort_unless($preset, 404);
        abort_if($preset->isBuiltin(), 403, 'Preset bawaan tidak bisa diubah atau dihapus.');

        return $preset;
    }
}
