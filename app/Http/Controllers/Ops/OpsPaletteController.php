<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Services\Ops\PaletteSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OpsPaletteController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 403);

        return response()->json([
            'q' => trim((string) $request->query('q', '')),
            'groups' => (new PaletteSearch($user))->search((string) $request->query('q', '')),
        ]);
    }
}
