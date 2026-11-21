<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * ffmpeg ran and rejected the command. The same inputs fail the same way
 * again, so jobs fail at once instead of spending a retry on it.
 */
class FFmpegFailed extends RuntimeException {}
