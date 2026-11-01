<?php

namespace App\Exceptions;

use RuntimeException;

/** The clip can't go to that platform (too long, missing scope, no public URL); the message says why. */
class CannotPost extends RuntimeException {}
