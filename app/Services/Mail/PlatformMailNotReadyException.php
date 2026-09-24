<?php

namespace App\Services\Mail;

use RuntimeException;

/**
 * Thrown when a queued ops notification is delivered but platform SMTP is not
 * configured (any more); the job retries, then marks the row failed.
 */
final class PlatformMailNotReadyException extends RuntimeException {}
