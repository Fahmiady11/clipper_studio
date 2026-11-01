<?php

namespace App\Services\Social;

use RuntimeException;

/** The clip or account cannot be published to: the message is safe to show the user. */
class PublishRefused extends RuntimeException {}
