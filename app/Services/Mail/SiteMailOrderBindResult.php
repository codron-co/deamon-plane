<?php

namespace App\Services\Mail;

class SiteMailOrderBindResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $orderId = null,
        public readonly ?string $mailDomain = null,
    ) {}

    public static function matched(string $orderId, string $mailDomain): self
    {
        return new self('matched', $orderId, $mailDomain);
    }

    public static function unmatched(): self
    {
        return new self('unmatched');
    }

    public static function cleared(): self
    {
        return new self('cleared');
    }

    public static function lookupFailed(): self
    {
        return new self('lookup_failed');
    }

    public function isMatched(): bool
    {
        return $this->status === 'matched';
    }

    public function isUnmatched(): bool
    {
        return $this->status === 'unmatched';
    }

    public function isLookupFailed(): bool
    {
        return $this->status === 'lookup_failed';
    }
}
