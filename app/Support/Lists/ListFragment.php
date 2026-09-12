<?php

namespace App\Support\Lists;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * An ops list URL answers twice: the whole page for a normal visit, and only the
 * table region when the toolbar asks for it over fetch. Both come from the same
 * route and the same authorization, so search, filters, sort and pagination stay
 * ordinary links and GET forms when JavaScript is unavailable.
 */
final class ListFragment
{
    public const HEADER = 'X-Ops-List-Fragment';

    public const VALUE = 'region';

    public static function wanted(Request $request): bool
    {
        return $request->header(self::HEADER) === self::VALUE;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function respond(Request $request, string $page, string $region, array $data): Response
    {
        $wanted = self::wanted($request);

        return response()
            ->view($wanted ? $region : $page, $data)
            // One URL, two representations: keep any cache in between honest.
            ->header('Vary', self::HEADER)
            ->header('X-Ops-List-Region', $wanted ? '1' : '0');
    }
}
