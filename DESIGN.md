# DESIGN.md

Direction for the UI revamp (branch `revamp/ui`). Choices made by the owner on 2026-10-17.

## Audience
Solo creator cutting long videos and podcasts into short clips, often from a phone.

## Personality
Energetic creator tool: bold, fast, playful. Closer to the TikTok / Shorts world than to a pro editing suite.

## Theme
Dark and light, following the system setting with a manual toggle.

## Color
Accent: lime `#D4FF3A`.

| Token | Dark | Light |
|---|---|---|
| `--bg` | `#0A0B0A` | `#FAFAF7` |
| `--text` | `#EDEDE8` | `#0A0B0A` |
| `--text-muted` | `#9DA295` | `#50554B` |
| `--text-dim` | `#7E8379` | `#6A6F64` |
| `--accent` (fills) | `#D4FF3A` | `#D4FF3A` |
| `--on-accent` | `#0A0B0A` | `#0A0B0A` |
| `--accent-ink` (accent as text/links) | `#D4FF3A` | `#4A6300` |

Contrast checked: lime on dark bg 17:1, dark text on lime 17:1, `--text-dim` 4.4:1 or better on bg, surface and surface-2 (keep it off surface-3), `--accent-ink` light 6.5:1.
Full token list: `resources/css/theme.css`.
Lime on the light background is 1.1:1, so in light mode lime is a fill only, never text or a thin line.

## Typography
Geist for UI and headings, Geist Mono for timecodes, numbers and technical values.

## Order of work
1. Foundation: tokens, theme switch, shared components (nav, buttons, cards, forms).
2. Studio (input, results, editor).
3. Welcome.
4. Autopilot, music, login/register, admin, privacy/terms.
