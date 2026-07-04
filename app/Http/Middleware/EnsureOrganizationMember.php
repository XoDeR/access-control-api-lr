<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Services\PermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOrganizationMember
{
    public function __construct(private PermissionService $permissionService) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $organization = $request->route('organization');

        if (! $organization instanceof Organization) {
            $organization = Organization::query()
                ->where('uuid', $organization)
                ->orWhere('id', $organization)
                ->firstOrFail();
            $request->route()->setParameter('organization', $organization);
        }

        $membership = $this->permissionService->getMembership($user, $organization);

        if (! $membership) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $request->attributes->set('organization', $organization);
        $request->attributes->set('membership', $membership);

        return $next($request);
    }
}
