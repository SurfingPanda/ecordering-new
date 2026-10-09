<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\EcposStores;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class EcposStoreSyncController extends Controller
{
    /** Admin button "Sync from ECPOS" on the Stores page. */
    public function __invoke(EcposStores $stores): JsonResponse
    {
        try {
            return response()->json($stores->sync());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }
}
