#!/bin/sh
# Rebuilds the clip sound effects from nothing but ffmpeg synthesis (no samples,
# no downloads), so they carry no licence. Run from anywhere: sh resources/sfx/generate.sh
# Each file peaks around -6 dBFS; SfxCues::GAIN sets the level in the mix.
set -e
cd "$(dirname "$0")"
FF="${FFMPEG_PATH:-ffmpeg}"
FMT="-ac 1 -ar 44100 -c:a pcm_s16le"

# whoosh: pink noise through a band that opens and closes, peaking at 0.25s (SfxCues::WHOOSH_LEAD)
$FF -v error -y -f lavfi -i "anoisesrc=d=0.5:c=pink:r=44100:a=1:s=7" -filter_complex \
  "[0:a]asplit=2[a][b];\
[a]highpass=f=200,lowpass=f=1200,volume='exp(-pow((t-0.25)/0.11,2))':eval=frame[lo];\
[b]highpass=f=1800,lowpass=f=5000,volume='0.35*exp(-pow((t-0.26)/0.06,2))':eval=frame[hi];\
[lo][hi]amix=inputs=2:normalize=0,volume=1.6,afade=t=out:st=0.42:d=0.08[out]" -map "[out]" $FMT whoosh.wav

# pop: a soft bubble, a sine falling 520 -> 200 Hz with a 4ms attack (no click)
$FF -v error -y -f lavfi -i "aevalsrc='sin(2*PI*(200*t+320*0.03*(1-exp(-t/0.03))))*exp(-t/0.05)*min(1,t/0.004)*0.5':s=44100:d=0.18" $FMT pop.wav

# ding: a short marimba-like tick (A5 with its 4th partial), for a punchline
$FF -v error -y -f lavfi -i "aevalsrc='(sin(2*PI*880*t)*exp(-t/0.16)+0.25*sin(2*PI*3520*t)*exp(-t/0.04))*min(1,t/0.004)*0.45':s=44100:d=0.6" \
  -af "afade=t=out:st=0.45:d=0.15" $FMT ding.wav
