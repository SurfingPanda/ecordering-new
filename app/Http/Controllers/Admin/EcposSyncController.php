<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\EcposCatalog;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class EcposSyncController extends Controller
{
    /** Admin button "Sync from ECPOS": pulls the BW Products catalog and reports what changed. */
    public function __invoke(EcposCatalog $catalog): JsonResponse
    {
        try {
            return response()->json($catalog->sync());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }
}
