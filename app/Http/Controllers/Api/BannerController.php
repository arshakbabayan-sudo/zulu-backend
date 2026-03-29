<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use Illuminate\Http\JsonResponse;

class BannerController extends Controller
{
    public function index(): JsonResponse
    {
        $banners = Banner::orderBy('sort_order')->get();
        
        return response()->json([
            'success' => true,
            'data' => $banners
        ]);
    }
}
