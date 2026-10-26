<?php

namespace App\Services\Comail;

use App\Models\SiteComailDomain;

/**
 * Mail security records of a local-mode CoMail domain D (MX on CoMail):
 *
 *  - dmarc        TXT _dmarc.D        v=DMARC1; p=<policy>; rua=mailto:<dmarc rua>; fo=1
 *  - mta_sts      A   mta-sts.D       <CoMail IPv4> (DNS only; mailcow serves the policy)
 *  - mta_sts_txt  TXT _mta-sts.D      v=STSv1; id=<YmdHis>
 *  - tls_rpt      TXT _smtp._tls.D    v=TLSRPTv1; rua=mailto:<tlsrpt rua>
 *
 * plus, in the report domain's zone, `D._report._dmarc.<rua domain>` TXT v=DMARC1
 * (external report authorization, needed because rua points to another domain).
 * Pure values and comparisons; the writes live in ComailSecurity.
 */
final class ComailSecurityRecords
{
    public const KEYS = ['mta_sts', 'mta_sts_txt', 'tls_rpt'];

    public static function mailIpv4(): string
    {
        return (string) config('ops.comail.mail_ipv4', '31.97.79.49');
    }

    public static function dmarcRua(): string
    {
        return strtolower((string) config('ops.comail.dmarc_rua', 'dmarc@codron.co'));
    }

    public static function tlsRptRua(): string
    {
        return strtolower((string) config('ops.comail.tlsrpt_rua', 'tlsrpt@codron.co'));
    }

    public static function dmarcValue(string $policy): string
    {
        return 'v=DMARC1; p='.$policy.'; rua=mailto:'.self::dmarcRua().'; fo=1';
    }

    public static function tlsRptValue(): string
    {
        return 'v=TLSRPTv1; rua=mailto:'.self::tlsRptRua();
    }

    public static function mtaStsValue(string $id): string
    {
        return 'v=STSv1; id='.$id;
    }

    /**
     * The _mta-sts id: the stored one, else the moment MX moved to CoMail (stable
     * for the manual record list until Plane creates the policy).
     */
    public static function mtaStsId(SiteComailDomain $row): string
    {
        if (filled($row->mta_sts_id)) {
            return (string) $row->mta_sts_id;
        }

        return ($row->mx_active_at ?? $row->created_at ?? now())->format('YmdHis');
    }

    /**
     * Records added to ComailDns::plan() for a local-mode domain (DMARC is already
     * in the v1 plan; its value comes from dmarcValue()).
     *
     * @return list<array{key: string, type: string, name: string, value: string}>
     */
    public static function records(SiteComailDomain $row): array
    {
        $domain = strtolower($row->domain);

        return [
            ['key' => 'mta_sts', 'type' => 'A', 'name' => 'mta-sts.'.$domain, 'value' => self::mailIpv4()],
            ['key' => 'mta_sts_txt', 'type' => 'TXT', 'name' => '_mta-sts.'.$domain, 'value' => self::mtaStsValue(self::mtaStsId($row))],
            ['key' => 'tls_rpt', 'type' => 'TXT', 'name' => '_smtp._tls.'.$domain, 'value' => self::tlsRptValue()],
        ];
    }

    /**
     * Domain of the DMARC report mailbox (codron.co); its zone gets the authorization records.
     */
    public static function reportDomain(): string
    {
        return ComailDirectory::domainOf(self::dmarcRua());
    }

    /**
     * Authorization record name, or null when D shares the report domain (no
     * external authorization needed for codron.co itself or its subdomains).
     */
    public static function reportAuthorizationName(string $domain): ?string
    {
        $domain = strtolower($domain);
        $reportDomain = self::reportDomain();
        if ($reportDomain === '' || $domain === $reportDomain || str_ends_with($domain, '.'.$reportDomain)) {
            return null;
        }

        return $domain.'._report._dmarc.'.$reportDomain;
    }

    // ── DMARC ───────────────────────────────────────────────────────────

    /**
     * Tags of a DMARC record in order, keys lowercase (`v`, `p`, `rua`, …).
     *
     * @return array<string, string>
     */
    public static function tags(string $value): array
    {
        $tags = [];
        foreach (explode(';', trim($value, " \t\"")) as $part) {
            $eq = strpos($part, '=');
            if ($eq === false) {
                continue;
            }
            $key = strtolower(trim(substr($part, 0, $eq)));
            if ($key !== '' && ! isset($tags[$key])) {
                $tags[$key] = trim(substr($part, $eq + 1));
            }
        }

        return $tags;
    }

    public static function isDmarc(string $value): bool
    {
        return strtolower(self::tags($value)['v'] ?? '') === 'dmarc1';
    }

    public static function strictness(string $policy): int
    {
        return match (strtolower(trim($policy))) {
            'reject' => 2,
            'quarantine' => 1,
            default => 0,
        };
    }

    /**
     * Whether the record already sends aggregate reports to CoMail (a record Plane wrote).
     */
    public static function reportsToComail(string $value): bool
    {
        $rua = strtolower(str_replace(' ', '', self::tags($value)['rua'] ?? ''));

        return in_array('mailto:'.self::dmarcRua(), explode(',', $rua), true);
    }

    /**
     * New content for an existing `_dmarc` record, or null to leave it alone.
     *
     * A customer's own record with a stricter policy than requested is never touched.
     * Otherwise the policy is set, CoMail's report address is added next to any the
     * customer already has, `fo=1` is added when no `fo` exists, and every other tag
     * (sp, pct, adkim, ruf, …) stays as it was.
     */
    public static function mergeDmarc(?string $existing, string $policy): ?string
    {
        if ($existing === null || ! self::isDmarc($existing)) {
            return self::dmarcValue($policy);
        }

        $tags = self::tags($existing);
        if (! self::reportsToComail($existing) && self::strictness($tags['p'] ?? 'none') > self::strictness($policy)) {
            return null;
        }

        $tags['p'] = $policy;
        $rua = trim($tags['rua'] ?? '');
        if (! self::reportsToComail($existing)) {
            $tags['rua'] = ($rua !== '' ? $rua.',' : '').'mailto:'.self::dmarcRua();
        }
        $tags['fo'] ??= '1';

        // v first, p second (RFC 7489), then the rest in their original order.
        unset($tags['v']);
        $out = ['v=DMARC1', 'p='.$tags['p']];
        unset($tags['p']);
        foreach ($tags as $key => $value) {
            $out[] = $key.'='.$value;
        }

        return implode('; ', $out);
    }

    /**
     * ok: a DMARC record with the requested policy that reports to CoMail;
     * different: any other DMARC record (e.g. a stricter customer policy).
     *
     * @param  list<string>  $values
     * @return array{0: string, 1: string|null}
     */
    public static function verifyDmarc(array $values, string $policy): array
    {
        foreach ($values as $value) {
            if (! self::isDmarc($value)) {
                continue;
            }

            $tags = self::tags($value);
            $ok = strtolower($tags['p'] ?? '') === $policy && self::reportsToComail($value);

            return [$ok ? 'ok' : 'different', $ok ? null : $value];
        }

        return ['missing', null];
    }

    /**
     * Status of a `v=<prefix>` TXT (STSv1, TLSRPTv1). TLS-RPT also has to report to CoMail.
     *
     * @param  list<string>  $values
     * @return array{0: string, 1: string|null}
     */
    public static function verifyTxt(string $key, array $values): array
    {
        $prefix = $key === 'tls_rpt' ? 'v=tlsrptv1' : 'v=stsv1';
        foreach ($values as $value) {
            $flat = strtolower(str_replace(' ', '', $value));
            if (! str_starts_with($flat, $prefix)) {
                continue;
            }
            if ($key === 'tls_rpt' && ! str_contains($flat, 'mailto:'.self::tlsRptRua())) {
                return ['different', $value];
            }

            return ['ok', null];
        }

        return $values === [] ? ['missing', null] : ['different', implode(', ', $values)];
    }

    /**
     * @param  list<string>  $ips
     * @return array{0: string, 1: string|null}
     */
    public static function verifyAddress(array $ips): array
    {
        $ips = array_values(array_filter($ips, static fn (string $ip): bool => $ip !== ''));
        if ($ips === []) {
            return ['missing', null];
        }

        return in_array(self::mailIpv4(), $ips, true) ? ['ok', null] : ['different', implode(', ', $ips)];
    }
}
