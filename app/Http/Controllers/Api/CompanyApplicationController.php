<?php

namespace App\Http\Controllers\Api;

use App\Events\CompanyApplicationSubmitted;
use App\Http\Controllers\Api\Concerns\PaginatesCommerceResources;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\CompanyApplicationResource;
use App\Models\CompanyApplication;
use App\Services\Admin\AdminAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class CompanyApplicationController extends Controller
{
    use PaginatesCommerceResources;

    public function __construct(
        private AdminAccessService $adminAccessService
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_name'   => ['required', 'string', 'max:255'],
            'company_type'   => ['required', 'string', Rule::in(['agent', 'operator'])],
            'business_email' => ['required', 'email', 'max:255', 'unique:company_applications,business_email'],
            'legal_address'  => ['required', 'string', 'max:500'],
            'actual_address' => ['required', 'string', 'max:500'],
            'country'        => ['required', 'string', 'max:100'],
            'city'           => ['required', 'string', 'max:100'],
            'phone'          => ['required', 'string', 'max:50'],
            'tax_id'         => ['required', 'string', 'max:100'],
            'contact_person' => ['required', 'string', 'max:255'],
            'position'       => ['required', 'string', 'max:255'],
            'state_certificate' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'license'        => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $year     = now()->format('Y');
        $month    = now()->format('m');
        $basePath = "company_applications/{$year}/{$month}";

        $stateCertificatePath = $request->file('state_certificate')->store($basePath, 'local');
        $licensePath = $request->hasFile('license')
            ? $request->file('license')->store($basePath, 'local')
            : null;

        $application = CompanyApplication::query()->create([
            'company_name'           => $validated['company_name'],
            'company_type'           => $validated['company_type'],
            'business_email'         => $validated['business_email'],
            'legal_address'          => $validated['legal_address'],
            'actual_address'         => $validated['actual_address'],
            'country'                => $validated['country'],
            'city'                   => $validated['city'],
            'phone'                  => $validated['phone'],
            'tax_id'                 => $validated['tax_id'],
            'contact_person'         => $validated['contact_person'],
            'position'               => $validated['position'],
            'state_certificate_path' => $stateCertificatePath,
            'license_path'           => $licensePath,
            'status'                 => CompanyApplication::STATUS_PENDING,
            'submitted_at'           => now(),
        ]);

        try {
            event(new CompanyApplicationSubmitted($application));
        } catch (\Throwable $e) {
            Log::warning('Failed to dispatch application submitted event', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'message'        => 'Your application has been submitted successfully. We will review it shortly.',
                'application_id' => $application->id,
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->denyUnlessSuperAdmin($request)) {
            return $deny;
        }

        $application = CompanyApplication::query()
            ->with('reviewer')
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => CompanyApplicationResource::make($application)->toArray($request),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessSuperAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in([
                CompanyApplication::STATUS_PENDING,
                CompanyApplication::STATUS_UNDER_REVIEW,
                CompanyApplication::STATUS_APPROVED,
                CompanyApplication::STATUS_REJECTED,
            ])],
            'company_type' => ['nullable', 'string', Rule::in(['agent', 'operator'])],
        ]);

        $query = CompanyApplication::query()
            ->with('reviewer')
            ->orderByDesc('submitted_at');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['company_type'])) {
            $query->where('company_type', $validated['company_type']);
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate(20)->withQueryString();

        return $this->paginatedCommerceResourceResponse($request, $paginator, CompanyApplicationResource::class);
    }

    private function denyUnlessSuperAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $this->adminAccessService->isSuperAdmin($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        return null;
    }
}
