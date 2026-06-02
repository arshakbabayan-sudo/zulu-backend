<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NewsletterSubscription;
use App\Services\Admin\AdminAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminNewsletterController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // Newsletter subscribers are a platform-wide B2C list — super-only.
        $caller = $request->user();
        if ($caller !== null && ! $this->adminAccessService->isSuperAdmin($caller)) {
            return response()->json(['success' => true, 'data' => [], 'meta' => [
                'current_page' => 1, 'last_page' => 1, 'total' => 0, 'per_page' => 25,
            ]]);
        }

        $perPage = (int) $request->query('per_page', 25);
        if ($perPage < 1) {
            $perPage = 25;
        }
        if ($perPage > 200) {
            $perPage = 200;
        }

        $query = NewsletterSubscription::query()->orderByDesc('subscribed_at');

        if ($source = $request->query('source')) {
            $query->where('source', $source);
        }
        if ($lang = $request->query('lang')) {
            $query->where('lang', $lang);
        }
        if ($search = $request->query('search')) {
            $query->where('email', 'ilike', '%'.$search.'%');
        }
        if ($request->boolean('active_only', true)) {
            $query->whereNull('unsubscribed_at');
        }

        $page = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function stats(): JsonResponse
    {
        $base = NewsletterSubscription::query()->whereNull('unsubscribed_at');

        return response()->json([
            'success' => true,
            'data' => [
                'total_active' => (clone $base)->count(),
                'by_lang' => (clone $base)
                    ->selectRaw('lang, count(*) as total')
                    ->groupBy('lang')
                    ->pluck('total', 'lang'),
                'by_source' => (clone $base)
                    ->selectRaw('source, count(*) as total')
                    ->groupBy('source')
                    ->pluck('total', 'source'),
            ],
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $filename = 'newsletter-subscribers-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($request): void {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM so Excel opens Cyrillic / Armenian correctly
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['email', 'lang', 'source', 'subscribed_at', 'unsubscribed_at', 'ip']);

            $query = NewsletterSubscription::query()->orderByDesc('subscribed_at');
            if ($request->boolean('active_only', true)) {
                $query->whereNull('unsubscribed_at');
            }
            if ($source = $request->query('source')) {
                $query->where('source', $source);
            }
            if ($lang = $request->query('lang')) {
                $query->where('lang', $lang);
            }

            $query->chunk(500, function ($rows) use ($out): void {
                foreach ($rows as $row) {
                    fputcsv($out, [
                        $row->email,
                        $row->lang,
                        $row->source,
                        $row->subscribed_at?->toIso8601String(),
                        $row->unsubscribed_at?->toIso8601String(),
                        $row->ip,
                    ]);
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $sub = NewsletterSubscription::query()->findOrFail($id);
        $sub->update(['unsubscribed_at' => now()]);

        return response()->json(['success' => true]);
    }
}
