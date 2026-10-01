<?php

namespace App\Support;

use RuntimeException;

/** Thrown inside a job when the user cancelled it; the job stops quietly instead of failing. */
class JobCancelled extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Dibatalkan oleh pengguna.');
    }
}
