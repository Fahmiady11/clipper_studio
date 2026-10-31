# Clipper Studio — Implementation Plan

> **Reference**: `docs/planning-new.md` (spec) + `resources/views/studio.blade.php` (design)
> **Session-persistent**: Check off items as done. New session reads this file first.

---

## Status Legend
- `[ ]` = todo
- `[~]` = in-progress
- `[x]` = done
- `[!]` = blocked / needs decision

---

## Current State (baseline)
- Laravel 11 · PHP 8.2+
- Old models: `ClipBatch`, `ClipJob`
- Old DB: `clip_batches`, `clip_jobs` tables
- Old services: `GeminiService` (analyzeVideo + fetchMeta), `VideoLayoutService` (FFmpeg), `GeminiTranscriber`, `GroqTranscriber`
- Old jobs: `GeminiAnalysisJob`, `ClipVideoJob`
- **No auth** (unauthenticated, route returns `studio` view)
- Frontend: `studio.blade.php` — full 7-step wizard, **mock data only** (no real API calls)

---

## Phase 1 — Database Migrations & Models
> New schema per `planning-new.md §4`. Keep old tables intact (don't drop).

- [x] **1.1** Migration: `create_clip_projects_table`
  - columns: `id` (uuid), `user_id` (FK), `youtube_url`, `video_title`, `duration_seconds`, `thumbnail_url`, `layout_type` (enum: reframe|gaussian), `clip_count` (1|3|5), `duration_mode` (auto|manual), `min_duration`, `max_duration`, `status` (draft|processing|done|failed), `timestamps`

- [x] **1.2** Migration: `create_subtitle_settings_table`
  - columns: `id`, `clip_project_id` (FK), `enabled` (bool), `font_family`, `font_size`, `text_color`, `highlight_color`, `position` (top|center|bottom), `background_style` (none|semi|full), `timestamps`

- [x] **1.3** Migration: `create_hook_settings_table`
  - columns: `id`, `clip_project_id` (FK), `enabled` (bool), `hook_text`, `is_ai_generated` (bool), `duration_seconds`, `position` (top|center|bottom), `text_color`, `background_style` (none|semi|full), `timestamps`

- [x] **1.4** Migration: `create_transcripts_table`
  - columns: `id`, `clip_project_id` (FK), `language`, `content` (JSON text), `timestamps`

- [x] **1.5** Migration: `create_generated_clips_table`
  - columns: `id` (uuid), `clip_project_id` (FK), `ranking`, `start_seconds` (float), `end_seconds` (float), `topic`, `reason`, `viral_potential` (tinggi|sedang|rendah), `hook_text`, `subtitle_json` (JSON), `output_path` (nullable), `status` (pending|processing|done|failed), `timestamps`

- [x] **1.6** Migration: `add_avatar_to_users_table` (nullable string)

- [x] **1.7** Model: `ClipProject` (fillable, casts, relationships: user, subtitleSetting, hookSetting, transcript, generatedClips)

- [x] **1.8** Model: `SubtitleSetting` (fillable, casts bool `enabled`, belongsTo ClipProject)

- [x] **1.9** Model: `HookSetting` (fillable, casts bool `enabled`/`is_ai_generated`, belongsTo ClipProject)

- [x] **1.10** Model: `Transcript` (fillable, cast `content` as array, belongsTo ClipProject)

- [x] **1.11** Model: `GeneratedClip` (uuid PK, fillable, casts, belongsTo ClipProject)

---

## Phase 2 — Authentication
> Laravel Breeze-style manual (no package install). Email/password only. Structure ready for Google OAuth.

- [x] **2.1** Migration: ensure `users` table has `avatar` column (from 1.6)

- [x] **2.2** `RegisterController` + `RegisterRequest`
  - fields: name, email, password, password_confirmation
  - creates user, logs in, redirects to `/`
  - TODO comment for Google OAuth (Socialite)

- [x] **2.3** `LoginController`
  - validates credentials, `Auth::attempt()`, redirect to `/`
  - TODO comment for Google OAuth

- [x] **2.4** `LogoutController` (or method in LoginController)

- [x] **2.5** View: `resources/views/auth/login.blade.php`
  - Same design tokens as studio.blade.php (dark bg, `--teal`, Inter font)
  - Centered card, email + password fields, submit btn

- [x] **2.6** View: `resources/views/auth/register.blade.php`
  - Same design tokens
  - name + email + password + confirm fields

- [x] **2.7** Auth middleware on all non-auth routes
  - `/` → requires auth → redirects to `/login`

---

## Phase 3 — Services
> Refactor/extend existing services. Keep `GeminiService` (already works).

- [x] **3.1** `YtDlpService`
  - `getMetadata(string $url): array` → runs `yt-dlp --dump-json`, returns `[title, duration_seconds, thumbnail_url, channel, language]`
  - `download(string $url, string $outPath): void` → runs `yt-dlp -o outPath url`
  - Uses `config('services.ytdlp.path', 'yt-dlp')` binary
  - `escapeshellarg` on all inputs

- [x] **3.2** `TranscriptService`
  - `fetch(string $youtubeUrl): array` → attempts YouTube transcript API, falls back to Gemini transcription
  - Returns `[language: string, segments: [{start, end, text}]]`
  - Wraps existing `GeminiTranscriber` / `GroqTranscriber`

- [x] **3.3** `FFmpegService` (replaces/wraps `VideoLayoutService`)
  - `cut(string $input, float $start, float $end, string $output): void`
  - `applyLayout(string $input, string $layout, string $output): void` — reframe|gaussian per planning-new.md §6
  - `burnSubtitles(string $input, string $assPath, string $output): void`
  - `buildAssFile(array $segments, array $subtitleSettings): string` — returns .ass content with `{\k}` karaoke tags
  - `buildHookVideo(string $hookText, float $duration, array $hookSettings, string $output): void`
  - `concatVideos(array $inputs, string $output): void`
  - Uses `config('services.ffmpeg.path', 'ffmpeg')` binary
  - All shell args escaped

---

## Phase 4 — Jobs
> Replace old jobs. New jobs use new models.

- [x] **4.1** `AnalyzeVideoJob`
  - Constructor: `ClipProject $project`
  - Steps:
    1. Update `clip_projects.status = 'processing'`
    2. `YtDlpService::download()` → `storage/app/temp/{projectId}/video.mp4`
    3. `TranscriptService::fetch()` → save to `transcripts` table
    4. `GeminiService::analyzeVideo()` → adapt method to accept transcript + settings (clip_count, duration_mode, min/max)
    5. For each Gemini result: create `GeneratedClip` (status=pending)
    6. Update `clip_projects.status = 'done'`
  - `failed()`: update status = 'failed', log error

- [x] **4.2** `RenderClipJob`
  - Constructor: `GeneratedClip $clip`
  - Steps:
    1. Update `generated_clips.status = 'processing'`
    2. `FFmpegService::cut()` → temp cut file
    3. `FFmpegService::applyLayout()` → layout applied file
    4. If subtitle enabled: `FFmpegService::buildAssFile()` → burn subtitles
    5. If hook enabled: `FFmpegService::buildHookVideo()` → concat hook + main
    6. Move output to `storage/app/clips/{userId}/{projectId}/{clipId}.mp4`
    7. Update `generated_clips.output_path`, `status = 'done'`
  - `failed()`: update status = 'failed'

---

## Phase 5 — Controllers & Routes
> New controllers using new models. Keep old ClipController (backward compat).

- [x] **5.1** `ProjectController`
  - `store(StoreProjectRequest)` → create ClipProject + SubtitleSetting + HookSetting, dispatch AnalyzeVideoJob, return `{project_id, status}`
  - `status(string $projectId)` → return project status + generated_clips list
  - `show(string $projectId)` → return full project data (for result page)

- [x] **5.2** `StoreProjectRequest` FormRequest
  - Validates: youtube_url (regex), layout_type (in enum), clip_count (in 1,3,5), duration_mode, min/max_duration, subtitle.*, hook.*

- [x] **5.3** `RenderController` (new)
  - `render(Request, string $clipId)` → find GeneratedClip, dispatch RenderClipJob, return `{clip_id, status}`
  - `renderStatus(string $clipId)` → return clip status + output_path

- [x] **5.4** `DownloadController`
  - `download(string $clipId)` → serve `generated_clips.output_path` as file download

- [x] **5.5** Update `routes/web.php`
  ```php
  // Public
  Route::get('/login', [LoginController::class, 'show'])->name('login');
  Route::post('/login', [LoginController::class, 'store']);
  Route::get('/register', [RegisterController::class, 'show'])->name('register');
  Route::post('/register', [RegisterController::class, 'store']);
  Route::post('/logout', [LogoutController::class, 'destroy'])->name('logout');

  // Auth-protected
  Route::middleware('auth')->group(function () {
      Route::get('/', fn() => view('studio'))->name('home');
  });
  ```

- [x] **5.6** Update `routes/api.php`
  ```php
  Route::middleware('auth')->group(function () {
      Route::post('/projects', [ProjectController::class, 'store']);
      Route::get('/projects/{projectId}/status', [ProjectController::class, 'status']);
      Route::post('/clips/{clipId}/render', [RenderController::class, 'render']);
      Route::get('/clips/{clipId}/render-status', [RenderController::class, 'renderStatus']);
      Route::get('/clips/{clipId}/download', [DownloadController::class, 'download']);
  });
  ```
  > Keep existing old routes for backward compat.

---

## Phase 6 — Frontend (studio.blade.php)
> Keep all CSS + HTML structure exactly as-is. Only change Alpine.js data/methods to call real API.

- [x] **6.1** Add `@csrf` meta tag + fetch helper with CSRF header

- [x] **6.2** Topbar: replace hardcoded `"Rangga"` with `{{ Auth::user()->name }}` + initials

- [x] **6.3** Add logout button in topbar nav

- [x] **6.4** `processLink()` method → real API call
  - POST `/api/projects` with `{youtube_url, layout_type, clip_count, duration_mode, min_duration, max_duration, subtitle: {...}, hook: {...}}`
  - On success: store `projectId`, set `meta` from response
  - On error: show error state

  > **Note**: For step 1, we only validate URL + fetch metadata (not full create yet).
  > Add new API endpoint `GET /api/meta?url=...` → YtDlpService::getMetadata()

- [x] **6.5** Add `GET /api/meta` endpoint → `MetaController@show` → YtDlpService::getMetadata()

- [x] **6.6** `processLink()` → calls `GET /api/meta?url=...`, shows real title/duration/channel from yt-dlp

- [x] **6.7** Step 1–5 "Lanjut" button on step 5 → POST `/api/projects` with all settings → stores `this.projectId`

- [x] **6.8** `startGenerate()` → real polling
  - Uses stored `this.projectId`
  - Polls `GET /api/projects/{projectId}/status` every 3s
  - When status = 'done': maps `generated_clips` to `this.clips`, goStep(7)
  - When status = 'failed': show error

- [x] **6.9** Step 7 "Render clip ini" button → POST `/api/clips/{clipId}/render`
  - Polls `GET /api/clips/{clipId}/render-status` every 3s
  - When done: `this.done = true`, set real download URL

- [x] **6.10** Download button → real `/api/clips/{clipId}/download`

- [x] **6.11** Topbar "Riwayat" link → placeholder `/projects` route (list page, later)

---

## Phase 9 — Wizard State Persistence & History

- [x] **9.1** Session persist via `localStorage` (`clipper_studio_v1`)
  - PERSIST_KEYS: step, maxReached, done, locked, url, meta, layout, clip settings, subtitle settings, hook settings, generate state, clips, selectedClip, downloadUrl
  - `saveSession()` / `loadSession()` / `clearSession()`
  - `watchAll()` — `$watch` tiap key → auto-save on change

- [x] **9.2** Resume on refresh
  - `resumeIfNeeded()` di `init()` — deteksi `generateStarted && projectId && clips.length === 0 && step === 6` → resume polling
  - `resumeGeneratePolling(projectId)` — extracted dari `startGenerate()`, reusable

- [x] **9.3** Step lock setelah generate
  - State `locked: boolean` — set `true` setelah `POST /api/projects` sukses
  - `goStep(n)`: guard `if (locked && n < step) return`
  - `back()`: guard `if (locked) return` (kecuali `done = true` → boleh balik ke step 7)
  - Footer nav: sembunyikan "Kembali" saat locked, tampilkan locked-notice badge

- [x] **9.4** History via `localStorage` (`clipper_history_v1`)
  - Max 20 item, FIFO eviction
  - `upsertHistory(status)` — upsert by `sessionId`, dipanggil: step >= 3 (draft), generate done (generated), render done (rendered)
  - `deleteHistoryItem(id)` / `clearAllHistory()`
  - `restoreFromHistory(item)` — restore snapshot + resume polling jika perlu

- [x] **9.5** History panel UI (`studio/_history-panel.blade.php`)
  - Slide-in panel dari kanan, overlay backdrop
  - List item: thumbnail, title, layout badge, status badge (DRAFT/GENERATED/RENDERED), tanggal relatif
  - Aksi per item: "Lanjutkan" / "Pilih Clip" / "Lihat Hasil" + hapus
  - Hapus semua button di header panel
  - Counter badge di topbar link "Riwayat"

- [x] **9.6** CSS history panel di `resources/css/studio.css`
  - `.hist-overlay`, `.hist-panel`, `.hist-item`, `.hs-badge`, `.locked-notice`, dll

---

## Phase 7 — Auth Views
> Same design tokens as studio.blade.php. Dark, minimal, Inter font.

- [x] **7.1** `resources/views/auth/login.blade.php`
- [x] **7.2** `resources/views/auth/register.blade.php`
- [x] **7.3** `resources/views/layouts/auth.blade.php` (shared shell for auth pages)

---

## Phase 8 — Config & Env
- [x] **8.1** Add to `config/services.php`:
  ```php
  'gemini' => ['key' => env('GEMINI_API_KEY')],
  'ytdlp'  => ['path' => env('YTDLP_PATH', 'yt-dlp')],
  'ffmpeg' => ['path' => env('FFMPEG_PATH', 'ffmpeg')],
  ```
- [x] **8.2** `.env.example` entries: `GEMINI_API_KEY`, `YTDLP_PATH`, `FFMPEG_PATH`, `CLIP_STORAGE_PATH`, `TEMP_STORAGE_PATH`, `QUEUE_CONNECTION=database`

---

## Execution Order
Implement in this order (each phase unblocks next):

```
Phase 1 (DB) → Phase 2 (Auth) → Phase 3 (Services) → Phase 4 (Jobs)
     ↓
Phase 5 (Controllers + Routes)
     ↓
Phase 6 (Frontend wiring) + Phase 7 (Auth views) [parallel]
     ↓
Phase 8 (Config cleanup)
```

---

## Notes / Decisions
- **Keep old tables** (`clip_batches`, `clip_jobs`) — don't drop, backward compat
- **GeminiService** already works — adapt `analyzeVideo()` to accept pre-fetched transcript segments instead of re-fetching
- **FFmpegService** wraps (not replaces) `VideoLayoutService` — call it internally for layout filters
- **UUIDs** on `clip_projects` and `generated_clips`, auto-increment on settings tables
- **Queue**: `QUEUE_CONNECTION=database`, `ensureQueueWorkerRunning()` pattern already exists
- **Subtitle JSON** on `generated_clips.subtitle_json` stores `[{start, end, text}]` segments from Gemini/GROQ — used by RenderClipJob to build .ass file
