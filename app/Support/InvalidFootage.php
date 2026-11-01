<?php

namespace App\Support;

use RuntimeException;

/** An uploaded gameplay file that cannot be used; the message is shown to the user. */
class InvalidFootage extends RuntimeException {}
