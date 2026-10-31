<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBrandKitRequest;
use App\Models\BrandKit;
use App\Support\ClipSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class BrandKitController extends Controller
{
    public function edit(): View
    {
        return view('settings.brand', [
            'kit' => BrandKit::firstOrNew(['user_id' => Auth::id()]),
            'fontFamilies' => ClipSettings::FONT_FAMILIES,
        ]);
    }

    public function update(UpdateBrandKitRequest $request): RedirectResponse
    {
        $kit = BrandKit::firstOrNew(['user_id' => Auth::id()]);
        $data = collect($request->validated())->except('logo')->all();

        if ($request->hasFile('logo')) {
            $this->deleteLogo($kit);
            $data['logo_path'] = $request->file('logo')->storeAs(
                'brand/'.Auth::id(),
                'logo-'.Str::random(8).'.'.$request->file('logo')->extension(),
                'local',
            );
        }

        $kit->fill($data)->save();

        return back()->with('status', 'Brand kit disimpan.');
    }

    public function logo(): Response
    {
        $kit = BrandKit::where('user_id', Auth::id())->first();
        abort_unless($kit?->logo_path && Storage::disk('local')->exists($kit->logo_path), 404);

        return Storage::disk('local')->response($kit->logo_path);
    }

    public function destroyLogo(): RedirectResponse
    {
        $kit = BrandKit::where('user_id', Auth::id())->first();
        if ($kit) {
            $this->deleteLogo($kit);
            $kit->update(['logo_path' => null]);
        }

        return back()->with('status', 'Logo dihapus.');
    }

    private function deleteLogo(BrandKit $kit): void
    {
        if ($kit->logo_path) {
            Storage::disk('local')->delete($kit->logo_path);
        }
    }
}
