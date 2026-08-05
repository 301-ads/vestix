<?php

namespace App\Http\Controllers;

use App\Models\Position;
use App\Services\WhatIfSimulatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatIfSimulatorController extends Controller
{
    public function __invoke(Request $request, Position $position, WhatIfSimulatorService $simulator): JsonResponse
    {
        $user = auth()->user();

        abort_unless($user !== null, 401);
        abort_unless($position->isOwnedBy($user) || $user->can('view', $position), 403);
        abort_unless($position->status === 'closed', 422);

        $data = $request->validate([
            'stop' => ['nullable', 'numeric', 'min:0.01'],
            'exit' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        return response()->json(
            $simulator->simulate(
                $position,
                isset($data['stop']) ? (float) $data['stop'] : null,
                isset($data['exit']) ? (float) $data['exit'] : null,
            )
        );
    }
}
