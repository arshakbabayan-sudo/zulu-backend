<?php

use App\Http\Controllers\VoucherVerifyController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['service' => 'Zulu API', 'status' => 'ok']));

Route::get('/verify/{token}', [VoucherVerifyController::class, 'show'])
    ->where('token', '[a-f0-9]{64}')
    ->name('voucher.verify');
