<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class AuditService
{
    public function log(
        string $action,
        ?User $user = null,
        ?Organization $organization = null,
        ?string $resourceType = null,
        ?int $resourceId = null,
        ?array $metadata = null,
        ?string $ipAddress = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'user_id' => $user?->id,
            'organization_id' => $organization?->id,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'metadata' => $metadata,
            'ip_address' => $ipAddress,
            'created_at' => now(),
        ]);
    }
}
