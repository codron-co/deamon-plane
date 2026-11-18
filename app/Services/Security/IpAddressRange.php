<?php

namespace App\Services\Security;

use InvalidArgumentException;

/**
 * Parses and compares the addresses the fleet security screens accept: a single
 * IPv4/IPv6 address or a CIDR. Blocking is stricter than allowlisting: private,
 * loopback and reserved addresses (the proxy, Plane, the host) are refused, and so
 * are ranges wide enough to take a country offline by mistake.
 */
final class IpAddressRange
{
    public const MAX_BLOCK_V4_PREFIX = 16;

    public const MAX_BLOCK_V6_PREFIX = 32;

    /**
     * Canonical form: lowercase IPv6, no leading zeros, `/32`-style prefix only for ranges.
     */
    public static function normalize(string $input): string
    {
        [$address, $prefix] = self::split($input);
        $binary = @inet_pton($address);
        if ($binary === false) {
            throw new InvalidArgumentException('Not an IP address or CIDR.');
        }

        $canonical = (string) inet_ntop($binary);
        $max = strlen($binary) === 4 ? 32 : 128;

        return $prefix === null || $prefix === $max ? $canonical : $canonical.'/'.$prefix;
    }

    /**
     * Normalizes and refuses what must never be blocked fleet-wide.
     */
    public static function normalizeForBlock(string $input): string
    {
        $normalized = self::normalize($input);
        [$address, $prefix] = self::split($normalized);
        $isV4 = str_contains($address, '.');

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new InvalidArgumentException('Private, loopback and reserved addresses cannot be blocked.');
        }

        if ($prefix !== null && $prefix < ($isV4 ? self::MAX_BLOCK_V4_PREFIX : self::MAX_BLOCK_V6_PREFIX)) {
            throw new InvalidArgumentException('That range is too wide to block.');
        }

        return $normalized;
    }

    /**
     * True when $candidate (address or CIDR) lies inside $range (address or CIDR).
     */
    public static function contains(string $range, string $candidate): bool
    {
        try {
            [$rangeAddress, $rangePrefix] = self::split(self::normalize($range));
            [$candidateAddress, $candidatePrefix] = self::split(self::normalize($candidate));
        } catch (InvalidArgumentException) {
            return false;
        }

        $rangeBin = (string) inet_pton($rangeAddress);
        $candidateBin = (string) inet_pton($candidateAddress);
        if (strlen($rangeBin) !== strlen($candidateBin)) {
            return false;
        }

        $bits = strlen($rangeBin) * 8;
        $rangePrefix ??= $bits;
        // A wider candidate (a /16 against an allowlisted /24) is not "inside".
        if (($candidatePrefix ?? $bits) < $rangePrefix) {
            return false;
        }

        return self::sharesPrefix($rangeBin, $candidateBin, $rangePrefix);
    }

    /**
     * True when the two ranges overlap at all (either contains the other's network address).
     */
    public static function overlaps(string $a, string $b): bool
    {
        try {
            [$addressA] = self::split(self::normalize($a));
            [$addressB] = self::split(self::normalize($b));
        } catch (InvalidArgumentException) {
            return false;
        }

        return self::contains($a, $addressB) || self::contains($b, $addressA);
    }

    /**
     * @return array{0: string, 1: int|null}
     */
    private static function split(string $input): array
    {
        $input = trim($input);
        if (! str_contains($input, '/')) {
            return [$input, null];
        }

        [$address, $prefix] = explode('/', $input, 2);
        if (preg_match('/\A\d{1,3}\z/', $prefix) !== 1) {
            throw new InvalidArgumentException('Bad CIDR prefix.');
        }

        $binary = @inet_pton($address);
        if ($binary === false || (int) $prefix > strlen($binary) * 8) {
            throw new InvalidArgumentException('Bad CIDR prefix.');
        }

        return [$address, (int) $prefix];
    }

    private static function sharesPrefix(string $a, string $b, int $bits): bool
    {
        $bytes = intdiv($bits, 8);
        if ($bytes > 0 && substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }

        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }
}
