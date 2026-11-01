<?php

// Cost inputs are estimates for the per-render cost log; tune them to the real server and API bills.
return [
    'cpu_usd_per_hour' => (float) env('BILLING_CPU_USD_PER_HOUR', 0.05),
    'gemini_usd_per_call' => (float) env('BILLING_GEMINI_USD_PER_CALL', 0.01),
    'groq_usd_per_audio_hour' => (float) env('BILLING_GROQ_USD_PER_AUDIO_HOUR', 0.04),
];
