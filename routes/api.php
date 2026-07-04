<?php

use App\Http\Controllers\Api\V1\AcceptInvitationController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Middleware\AuthenticateJwt;
use App\Http\Middleware\EnsureOrganizationMember;
use App\Http\Middleware\RequirePermission;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::prefix('v1')->group(function (): void {
    Route::prefix('auth')->group(function (): void {
        Route::post('/signup', [AuthController::class, 'signup'])
            ->middleware('throttle:auth-signup');
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:auth-login');
        Route::post('/refresh', [AuthController::class, 'refresh'])
            ->middleware('throttle:auth-login');
        Route::post('/logout', [AuthController::class, 'logout'])
            ->middleware(AuthenticateJwt::class);
    });

    Route::post('/invitations/accept', AcceptInvitationController::class);

    Route::middleware(AuthenticateJwt::class)->group(function (): void {
        Route::post('/organizations', [OrganizationController::class, 'store']);

        Route::get('/sessions', [SessionController::class, 'index']);
        Route::delete('/sessions/{session}', [SessionController::class, 'destroy']);

        Route::prefix('organizations/{organization}')->middleware(EnsureOrganizationMember::class)->group(function (): void {
            Route::get('/', [OrganizationController::class, 'show']);

            Route::get('/members', [MemberController::class, 'index'])
                ->middleware(RequirePermission::class.':users.read');
            Route::patch('/members/{member}', [MemberController::class, 'update']);

            Route::post('/invitations', [InvitationController::class, 'store'])
                ->middleware(RequirePermission::class.':users.invite');

            Route::get('/audit-logs', [AuditLogController::class, 'index'])
                ->middleware(RequirePermission::class.':users.read');
        });
    });
});
