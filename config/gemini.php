<?php

declare(strict_types=1);

return [
    'api_key' => env('GEMINI_API_KEY'),
    'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
    'temperature' => (float) env('GEMINI_TEMPERATURE', 0.2),
    'top_p' => (float) env('GEMINI_TOP_P', 0.95),
    'top_k' => (int) env('GEMINI_TOP_K', 40),
];
