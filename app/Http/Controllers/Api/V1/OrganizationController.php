<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Services\OrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function __construct(private OrganizationService $organizationService) {}

    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $organization = $this->organizationService->create(
            $request->user(),
            $request->validated('name'),
            $request->validated('slug'),
        );

        return response()->json([
            'data' => new OrganizationResource($organization),
        ], 201);
    }

    public function show(Request $request): JsonResponse
    {
        $organization = $request->attributes->get('organization');

        return response()->json([
            'data' => new OrganizationResource($organization->load('owner')),
        ]);
    }
}
