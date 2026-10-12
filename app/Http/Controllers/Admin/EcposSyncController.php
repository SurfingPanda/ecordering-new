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
            $result = $catalog->sync();
            \App\Support\Activity::log('ecpos_sync', 'Items', collect($result)->only(['added', 'updated', 'archived', 'restored'])->map(fn ($n, $k) => "{$k} {$n}")->implode(', '));

            return response()->json($result);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }
}
