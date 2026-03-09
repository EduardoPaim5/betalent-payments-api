<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => config('app.name', 'BeTalentPayments'),
        'status' => 'ok',
        'docs' => '/up',
    ]);
});

Route::get('/up', function () {
    return response()->json([
        'status' => 'ok',
    ]);
});
