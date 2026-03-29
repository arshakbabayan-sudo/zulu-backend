<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['service' => 'Zulu API', 'status' => 'ok']));
