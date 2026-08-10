<?php

namespace App\Http\Controllers;

use App\Models\Position;
use App\Services\PositionPriceChartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PositionPriceChartController extends Controller
{
    public function __invoke(
        Request $request,
        Position $position,
        PositionPriceChartService $charts,
    ): JsonResponse {
        $user = auth()->user();

        abort_unless($user !== null, 401);
        abort_unless(
            $position->isOwnedBy($user) || $user->can('view', $position),
            403,
        );

        $range = $charts->normalizeRange((string) $request->query('range', '3M'));
        $payload = $charts->build($position, $range);

        abort_if($payload === null, 404, 'Geen historische koersdata beschikbaar.');

        return response()->json($payload);
    }
}
