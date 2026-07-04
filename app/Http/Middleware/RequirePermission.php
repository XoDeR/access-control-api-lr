<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Services\PermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function __construct(private PermissionService $permissionService) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        $organization = $request->attributes->get('organization')
            ?? $request->route('organization');

        if (! $organization instanceof Organization) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (! $this->permissionService->userHasPermission($user, $organization, $permission)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
