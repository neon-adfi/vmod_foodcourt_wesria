<?php

use Vmod\Circularity\Classes\ApiAccess;

Route::prefix('api/circularity/v1')->middleware(ApiAccess::class)->group(function () {
    Route::get('health', fn () => response()->json([
        'service' => 'circularity', 'api_version' => 'v1', 'status' => 'ok',
    ]));
});
