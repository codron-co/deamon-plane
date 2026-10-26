<?php

namespace App\Services\Sites;

/**
 * A Deamon site deploy was refused because no built `deamon-cms:<sha>` image
 * backs it (no finished builder deployment, the commit was never built, or the
 * builder could not be read). Nothing was sent to the site's Coolify app.
 */
class BuiltImageException extends ComposePackException
{
    /** The commit may still be building or being tagged: the same request can succeed later. */
    public bool $pending = false;

    public static function pending(string $message): self
    {
        $exception = new self($message);
        $exception->pending = true;

        return $exception;
    }
}
