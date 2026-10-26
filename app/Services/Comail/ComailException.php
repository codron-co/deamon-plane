<?php

namespace App\Services\Comail;

use RuntimeException;

/**
 * A refused CoMail request: `error` is the site API code (limit_mailboxes,
 * domain_not_owned, not_found, …) and the message is Turkish, ready for the CMS.
 */
class ComailException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self('comail_not_configured', 'E-posta hizmeti bu site için henüz hazır değil.');
    }

    public static function notFound(string $message = 'Kayıt bulunamadı.'): self
    {
        return new self('not_found', $message, 404);
    }

    public static function domainNotOwned(): self
    {
        return new self('domain_not_owned', 'Bu alan adı bu siteye ait değil ya da E-posta için açılmamış.');
    }

    public static function validation(string $message): self
    {
        return new self('validation_failed', $message);
    }
}
