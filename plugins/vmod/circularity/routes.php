<?php

use Vmod\Circularity\Classes\ApiAccess;
use Vmod\Circularity\Classes\CaptureApi;

Route::prefix('api/circularity/v1')->middleware(ApiAccess::class)->group(function () {
    Route::get('health', fn () => response()->json([
        'service' => 'circularity', 'api_version' => 'v1', 'status' => 'ok',
    ]));
});

Route::prefix('api/circularity/v1')->middleware(ApiAccess::class . ':read')->group(function () {
    Route::get('options', [CaptureApi::class, 'options']);
    Route::get('areas', [CaptureApi::class, 'areas']);
    Route::get('entries', [CaptureApi::class, 'entries']);
    Route::get('entries/{uuid}', [CaptureApi::class, 'entry'])->whereUuid('uuid');
});
Route::prefix('api/circularity/v1')->middleware(ApiAccess::class . ':write')->group(function () {
    Route::post('areas', [CaptureApi::class, 'saveArea']);
    Route::patch('areas/{id}', [CaptureApi::class, 'saveArea'])->whereNumber('id');
    Route::post('entries', [CaptureApi::class, 'saveEntry']);
    Route::patch('entries/{uuid}', [CaptureApi::class, 'saveEntry'])->whereUuid('uuid');
    Route::delete('entries/{uuid}', [CaptureApi::class, 'removeEntry'])->whereUuid('uuid');
});
