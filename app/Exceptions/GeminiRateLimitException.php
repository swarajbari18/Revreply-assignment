<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class GeminiRateLimitException extends Exception
{
    // Custom exception representing a 429 rate limit error from Gemini API
}
