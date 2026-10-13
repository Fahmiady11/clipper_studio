#!/usr/bin/env python3
"""
Plans where a vertical (9:16) crop should sit over time so it follows faces,
and on a multi-person shot, the person who is talking.

    face_track.py [--ffmpeg ffmpeg] --model yunet.onnx VIDEO START END

Prints JSON: {"crop_w": <crop width / frame width>, "faces": <bool>,
              "segments": [[t0, t1, x0, x1], ...]}
x0/x1 are the crop centre (0..1 of the frame width) at clip time t0/t1; the
crop moves linearly in between. A jump from one segment to the next is a cut.

Frames come from ffmpeg at SAMPLE_FPS (scaled down), faces from OpenCV YuNet.
The speaker is the face whose mouth area changes most between samples: the
patch is aligned on the face landmarks, so head movement mostly cancels out.
The whole clip is analysed before deciding, so each shot picks its speaker
from what happens across the shot, not just from its first frames.
"""
import argparse
import json
import subprocess
import sys

import cv2
import numpy as np

SAMPLE_FPS = 6
WORK_W = 960            # detection width; faces in a wide shot stay ~50px+
MIN_SCORE = 0.6
MIN_FACE_REL = 0.035    # ignore faces narrower than this share of the frame (posters, crowd)
SCENE_CUT = 28.0        # mean abs grey diff (0..255) between samples that counts as a camera cut
TALK_WINDOW = 0.8       # seconds either side used to judge who is talking
MIN_TURN = 1.5          # seconds: shorter speaker turns are folded into a neighbour
FIT_ALL = 0.85          # faces spanning less than this share of the crop are framed together
DEADZONE = 0.10         # of the crop width: smaller drifts don't move the camera
PAN_SPEED = 0.9         # max crop movement in crop widths per second while following
CUT_JUMP = 0.55         # a move this far (in crop widths) is cut to, not panned


def frames(ffmpeg, video, start, end):
    """Yields the source (width, height) first, then (t, bgr) at SAMPLE_FPS."""
    probe = cv2.VideoCapture(video)
    src_w = probe.get(cv2.CAP_PROP_FRAME_WIDTH)
    src_h = probe.get(cv2.CAP_PROP_FRAME_HEIGHT)
    probe.release()
    if not src_w or not src_h:
        raise RuntimeError('cannot read video size')
    yield src_w, src_h
    h = int(round(WORK_W * src_h / src_w / 2) * 2)
    cmd = [ffmpeg, '-v', 'error', '-ss', f'{start:.3f}', '-t', f'{end - start:.3f}', '-i', video,
           '-vf', f'fps={SAMPLE_FPS},scale={WORK_W}:{h}', '-an', '-f', 'rawvideo', '-pix_fmt', 'bgr24', '-']
    proc = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
    size = WORK_W * h * 3
    i = 0
    try:
        while True:
            buf = proc.stdout.read(size)
            if len(buf) < size:
                break
            yield i / SAMPLE_FPS, np.frombuffer(buf, np.uint8).reshape(h, WORK_W, 3)
            i += 1
    finally:
        proc.stdout.close()
        proc.wait()


def patch(gray, x0, y0, x1, y1, size):
    h, w = gray.shape
    x0, x1 = max(0, int(x0)), min(w, int(x1))
    y0, y1 = max(0, int(y0)), min(h, int(y1))
    if x1 - x0 < 4 or y1 - y0 < 4:
        return None
    p = cv2.resize(gray[y0:y1, x0:x1], size, interpolation=cv2.INTER_AREA).astype(np.float32)
    return (p - p.mean()) / (p.std() + 8.0)


def face_patches(gray, f):
    x, w, h = f[0], f[2], f[3]
    rmx, rmy, lmx, lmy = f[10:14]
    mouth = patch(gray, rmx - 0.12 * w, min(rmy, lmy) - 0.12 * h, lmx + 0.12 * w, max(rmy, lmy) + 0.2 * h, (32, 16))
    eyes = patch(gray, x + 0.1 * w, min(f[5], f[7]) - 0.12 * h, x + 0.9 * w, max(f[5], f[7]) + 0.12 * h, (32, 12))
    return mouth, eyes


def observe(ffmpeg, model, video, start, end):
    """Pass 1: per sample, the faces seen (tracked within a shot) and how much each mouth moved."""
    gen = frames(ffmpeg, video, start, end)
    src_w, src_h = next(gen)
    det, prev_small = None, None
    samples = []        # {'t', 'cut', 'faces': {track: (cx, w)}, 'talk': {track: energy}}
    last = {}           # track -> (cx, w, t, mouth, eyes)
    next_id = 0
    for t, img in gen:
        if det is None:
            det = cv2.FaceDetectorYN.create(model, '', (img.shape[1], img.shape[0]), MIN_SCORE, 0.3, 50)
        gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
        small = cv2.resize(gray, (64, 36), interpolation=cv2.INTER_AREA).astype(np.float32)
        cut = prev_small is not None and float(np.abs(small - prev_small).mean()) > SCENE_CUT
        prev_small = small
        if cut:
            last = {}

        W = float(img.shape[1])
        _, found = det.detect(img)
        found = [] if found is None else [f for f in found if f[2] >= MIN_FACE_REL * W]
        faces, talk = {}, {}
        for f in sorted(found, key=lambda f: -f[2]):
            cx, w = (f[0] + f[2] / 2) / W, f[2] / W
            near = [k for k, v in last.items() if k not in faces and abs(v[0] - cx) < max(v[1], w) * 0.6]
            k = min(near, key=lambda k: abs(last[k][0] - cx)) if near else None
            if k is None:
                k, next_id = next_id, next_id + 1
            mouth, eyes = face_patches(gray, f)
            prev = last.get(k)
            if prev and mouth is not None and prev[3] is not None and t - prev[2] < 0.6:
                m = float(np.abs(mouth - prev[3]).mean())
                e = float(np.abs(eyes - prev[4]).mean()) if eyes is not None and prev[4] is not None else 0.0
                talk[k] = max(0.0, m - 0.6 * e)
            last[k] = (cx, w, t, mouth, eyes)
            faces[k] = (cx, w)
        last = {k: v for k, v in last.items() if t - v[2] < 2.0}
        samples.append({'t': t, 'cut': cut, 'faces': faces, 'talk': talk})
    return min(1.0, src_h * 9 / 16 / src_w), samples


def shots(samples):
    """Split sample indexes at camera cuts."""
    out, cur = [], []
    for i, s in enumerate(samples):
        if s['cut'] and cur:
            out.append(cur)
            cur = []
        cur.append(i)
    if cur:
        out.append(cur)
    return out


def decide(crop_w, samples):
    """Pass 2: per sample, (target crop centre | None, subject key) with stable speaker turns."""
    win = max(1, int(TALK_WINDOW * SAMPLE_FPS))
    min_turn = int(MIN_TURN * SAMPLE_FPS)
    plan = [(None, None)] * len(samples)
    for shot in shots(samples):
        tracks = {k for i in shot for k in samples[i]['faces']}
        # Faces seen in under a fifth of the shot are flukes (a hand, a poster)
        seen = {k: sum(k in samples[i]['faces'] for i in shot) for k in tracks}
        tracks = {k for k in tracks if seen[k] >= max(1, len(shot) // 5)}
        if not tracks:
            continue

        pos = {k: float(np.median([samples[i]['faces'][k][0] for i in shot if k in samples[i]['faces']])) for k in tracks}
        wid = {k: float(np.median([samples[i]['faces'][k][1] for i in shot if k in samples[i]['faces']])) for k in tracks}
        left = min(pos[k] - wid[k] / 2 for k in tracks)
        right = max(pos[k] + wid[k] / 2 for k in tracks)
        if len(tracks) == 1 or right - left <= crop_w * FIT_ALL:
            key = 'all' if len(tracks) > 1 else next(iter(tracks))
            for i in shot:
                vis = [samples[i]['faces'][k] for k in tracks if k in samples[i]['faces']]
                if vis:
                    lo = min(cx - w / 2 for cx, w in vis)
                    hi = max(cx + w / 2 for cx, w in vis)
                    plan[i] = ((lo + hi) / 2, key)
            continue

        # Several people too far apart for one frame: follow whoever is talking
        talk = {k: np.array([samples[i]['talk'].get(k, np.nan) for i in shot]) for k in tracks}
        loud = []
        for j in range(len(shot)):
            a, b = max(0, j - win), min(len(shot), j + win + 1)
            score = {k: np.nanmean(talk[k][a:b]) if np.isfinite(talk[k][a:b]).any() else -1.0 for k in tracks}
            loud.append(max(score, key=lambda k: (score[k], wid[k])))

        # Fold short turns into the neighbouring one (repeat until all are long enough)
        while True:
            runs, j = [], 0
            while j < len(loud):
                e = j
                while e < len(loud) and loud[e] == loud[j]:
                    e += 1
                runs.append((j, e))
                j = e
            short = [r for r in runs if r[1] - r[0] < min_turn]
            if len(runs) == 1 or not short:
                break
            a, b = min(short, key=lambda r: r[1] - r[0])
            idx = runs.index((a, b))
            before = runs[idx - 1] if idx > 0 else None
            after = runs[idx + 1] if idx + 1 < len(runs) else None
            take = max((r for r in (before, after) if r), key=lambda r: r[1] - r[0])
            for j in range(a, b):
                loud[j] = loud[take[0]]

        for j, i in enumerate(shot):
            k = loud[j]
            cx = samples[i]['faces'].get(k, (pos[k], 0))[0]
            plan[i] = (cx, k)
    return plan


def camera(crop_w, samples, plan):
    """Crop centres per sample: hold still, pan slowly, cut on new shots/speakers or big moves."""
    half = crop_w / 2
    clamp = (lambda x: min(1 - half, max(half, x))) if crop_w < 1 else (lambda x: 0.5)
    first = next((p[0] for p in plan if p[0] is not None), 0.5)
    cam, who = clamp(first), None
    dt = 1 / SAMPLE_FPS
    points = []  # (t, x, cut_before)
    for s, (target, key) in zip(samples, plan):
        cut = False
        if target is not None:
            goal = clamp(target)
            gap = goal - cam
            if s['cut'] or (who is not None and key != who) or abs(gap) > CUT_JUMP * crop_w:
                cut, cam = abs(gap) > 1e-3, goal
            elif abs(gap) > DEADZONE * crop_w:
                step = PAN_SPEED * crop_w * dt
                cam += max(-step, min(step, gap * 0.5))
            who = key
        points.append((s['t'], cam, cut))
    return points


def segments(points, end_t):
    """Linear pieces between samples, merged where they continue on one line."""
    if not points:
        return []
    segs = []
    for (t0, x0, _), (t1, x1, cut) in zip(points, points[1:]):
        segs.append([t0, t1, x0, x0 if cut else x1])  # on a cut: hold, then jump at t1
    last_t, last_x, _ = points[-1]
    segs.append([last_t, max(end_t, last_t + 1 / SAMPLE_FPS), last_x, last_x])
    segs[0][0] = 0.0

    merged = [segs[0]]
    for s in segs[1:]:
        m = merged[-1]
        if abs(m[3] - s[2]) < 1e-4:
            slope = (m[3] - m[2]) / max(m[1] - m[0], 1e-6)
            if abs(m[2] + slope * (s[1] - m[0]) - s[3]) < 0.004:
                m[1], m[3] = s[1], s[3]
                continue
        merged.append(s)
    return [[round(a, 3), round(b, 3), round(c, 4), round(d, 4)] for a, b, c, d in merged]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--ffmpeg', default='ffmpeg')
    ap.add_argument('--model', required=True)
    ap.add_argument('--debug', action='store_true')
    ap.add_argument('video')
    ap.add_argument('start', type=float)
    ap.add_argument('end', type=float)
    a = ap.parse_args()

    crop_w, samples = observe(a.ffmpeg, a.model, a.video, a.start, a.end)
    plan = decide(crop_w, samples)
    if a.debug:
        for s, (cx, k) in zip(samples, plan):
            faces = ' '.join(f"{k}@{v[0]:.2f}/{s['talk'].get(k, -1):.2f}" for k, v in s['faces'].items())
            print(f"{s['t']:6.2f} {'CUT' if s['cut'] else '   '} {faces:40} -> {k} {cx if cx is not None else -1:.2f}", file=sys.stderr)
    faces = any(p[0] is not None for p in plan)
    segs = segments(camera(crop_w, samples, plan), a.end - a.start) if faces else []
    json.dump({'crop_w': round(crop_w, 4), 'faces': faces, 'segments': segs}, sys.stdout)


if __name__ == '__main__':
    main()
