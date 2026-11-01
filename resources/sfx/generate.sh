#!/bin/sh
# Rebuilds the clip sound effects from nothing but ffmpeg synthesis (no samples,
# no downloads), so they carry no licence. Run from anywhere: sh resources/sfx/generate.sh
set -e
cd "$(dirname "$0")"
FF="${FFMPEG_PATH:-ffmpeg}"
FMT="-ac 1 -ar 44100 -c:a pcm_s16le"

# whoosh: pink noise split in a low body and a bright edge, each swelling around 0.35s
$FF -v error -y -f lavfi -i "anoisesrc=d=0.6:c=pink:r=44100:a=1:s=7" -filter_complex \
  "[0:a]asplit=2[a][b];\
[a]highpass=f=150,lowpass=f=900,volume='0.9*exp(-pow((t-0.34)/0.13,2))':eval=frame[lo];\
[b]highpass=f=1500,lowpass=f=7000,volume='0.6*exp(-pow((t-0.36)/0.055,2))':eval=frame[hi];\
[lo][hi]amix=inputs=2:normalize=0,volume=2.2[out]" -map "[out]" $FMT whoosh.wav

# pop: a short sine that falls fast in pitch, like a bubble
$FF -v error -y -f lavfi -i "aevalsrc='sin(2*PI*(280*t+820*0.025*(1-exp(-t/0.025))))*exp(-t/0.045)*min(1,t/0.002)*0.9':s=44100:d=0.16" $FMT pop.wav

# ding: a bell note (G6) with two quiet overtones that die faster
$FF -v error -y -f lavfi -i "aevalsrc='(sin(2*PI*1568*t)*exp(-t/0.3)+0.35*sin(2*PI*3136*t)*exp(-t/0.14)+0.15*sin(2*PI*4704*t)*exp(-t/0.06))*min(1,t/0.003)*0.6':s=44100:d=0.8" \
  -af "afade=t=out:st=0.6:d=0.2" $FMT ding.wav
