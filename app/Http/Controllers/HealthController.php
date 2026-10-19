<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('SELECT 1');
        } catch (\Throwable) {
            return response()->json(['status' => 'error'], 503);
        }

        return response()->json(['status' => 'ok']);
    }
}
