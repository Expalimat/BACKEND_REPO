<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'PnC-OESS API is running.',
        'frontend' => 'http://localhost:5173',
    ]);
});
