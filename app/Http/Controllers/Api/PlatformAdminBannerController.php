<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Services\Admin\AdminAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PlatformAdminBannerController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessSuperAdmin($request)) {
            return $deny;
        }

        $banners = Banner::query()->orderBy('sort_order')->get();

        return response()->json([
            'success' => true,
            'data' => $banners->map(fn (Banner $b) => $this->bannerPayload($b))->values()->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessSuperAdmin($request)) {
            return $deny;
        }

        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,webp|max:2048',
            'title_en' => 'nullable|string|max:255',
            'title_ru' => 'nullable|string|max:255',
            'title_hy' => 'nullable|string|max:255',
            'link_url' => 'nullable|url',
            'sort_order' => 'integer',
        ]);

        $data = $request->only(['title_en', 'title_ru', 'title_hy', 'link_url', 'sort_order']);

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('banners', 'public');
        }

        $banner = Banner::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Banner created successfully',
            'data' => $this->bannerPayload($banner->fresh()),
        ], 201);
    }

    public function update(Request $request, Banner $banner): JsonResponse
    {
        if ($deny = $this->denyUnlessSuperAdmin($request)) {
            return $deny;
        }

        $request->validate([
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'title_en' => 'nullable|string|max:255',
            'title_ru' => 'nullable|string|max:255',
            'title_hy' => 'nullable|string|max:255',
            'link_url' => 'nullable|url',
            'sort_order' => 'integer',
        ]);

        $data = $request->only(['title_en', 'title_ru', 'title_hy', 'link_url', 'sort_order']);

        if ($request->hasFile('image')) {
            if ($banner->image_path) {
                Storage::disk('public')->delete($banner->image_path);
            }
            $data['image_path'] = $request->file('image')->store('banners', 'public');
        }

        $banner->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Banner updated successfully',
            'data' => $this->bannerPayload($banner->fresh()),
        ]);
    }

    public function destroy(Request $request, Banner $banner): JsonResponse
    {
        if ($deny = $this->denyUnlessSuperAdmin($request)) {
            return $deny;
        }

        if ($banner->image_path) {
            Storage::disk('public')->delete($banner->image_path);
        }
        $id = (int) $banner->id;
        $banner->delete();

        return response()->json([
            'success' => true,
            'message' => 'Banner deleted successfully',
            'data' => ['id' => $id],
        ]);
    }

    private function bannerPayload(Banner $banner): array
    {
        return [
            'id' => $banner->id,
            'image_path' => $banner->image_path,
            'image_url' => $banner->image_path !== null && $banner->image_path !== ''
                ? Storage::disk('public')->url($banner->image_path)
                : null,
            'title_en' => $banner->title_en,
            'title_ru' => $banner->title_ru,
            'title_hy' => $banner->title_hy,
            'link_url' => $banner->link_url,
            'sort_order' => (int) $banner->sort_order,
            'is_active' => (bool) $banner->is_active,
            'created_at' => $banner->created_at?->toIso8601String(),
            'updated_at' => $banner->updated_at?->toIso8601String(),
        ];
    }

    private function denyUnlessSuperAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $this->adminAccessService->isSuperAdmin($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        return null;
    }
}
