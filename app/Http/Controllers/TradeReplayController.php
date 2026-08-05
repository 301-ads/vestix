<?php

namespace App\Http\Controllers;

use App\Models\Position;
use App\Services\TradeReplayService;
use Illuminate\Http\JsonResponse;

class TradeReplayController extends Controller
{
    public function __invoke(Position $position, TradeReplayService $replay): JsonResponse
    {
        $user = auth()->user();

        abort_unless($user !== null, 401);
        abort_unless(
            $position->isOwnedBy($user) || $user->can('view', $position),
            403,
        );

        $payload = $replay->build($position);

        abort_if($payload === null, 404, 'Geen historische koersdata beschikbaar voor replay.');

        return response()->json($payload);
    }
}
