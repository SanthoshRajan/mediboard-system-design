<?php

namespace App\Http\Middleware;

use Log;
use Closure;
use Illuminate\Http\Request;
use App\Services\FacilityService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

/**
 * ValidateFacility Middleware
 *
 * Central request gate for all routes. Runs on every request and is
 * responsible for three things:
 *
 *   1. Session validation - ensures facility_id, user_id, and group_name
 *      are present before allowing access to any protected route.
 *
 *   2. Tenant configuration - calls FacilityService::configureTenant() to
 *      set the correct per-tenant DB connection for the lifetime of the request.
 *      See: docs/TENANT_RESOLUTION.md
 *
 *   3. API auth passthrough - login and password-update routes have no session
 *      yet. These are handled separately via handleApiAuthRequest(), which
 *      resolves the facility from the request body before passing through.
 *
 * Security design decisions:
 *   - Unauthenticated API responses use obfuscated error codes and generic
 *     messages - no information about why authentication failed is leaked.
 *   - Invalid facility on login: a timing-safe dummy hash check is performed
 *     before returning a 401 to prevent timing-based facility enumeration.
 *   - All error responses are identical in structure regardless of failure reason.
 */
class ValidateFacility
{
    /**
     * Routes that render login/password pages - no session required.
     * Logged-in users are redirected away from these.
     */
    private const PUBLIC_VIEW_ROUTES = [
        'App\Http\Controllers\v1\AuthController@loginPage',
        'App\Http\Controllers\v1\AuthController@passwordUpdatePage',
    ];

    /**
     * API endpoints called during authentication - no session yet,
     * but facility must be resolved from the request body.
     */
    private const API_AUTH_ROUTES = [
        'App\Http\Controllers\v1\AuthController@authenticate',
        'App\Http\Controllers\v1\AuthController@updateExpiredPassword',
    ];

    public function handle(Request $request, Closure $next)
    {
        $facilityId    = session('facility_id');
        $userId        = session('user_id');
        $groupName     = session('group_name');
        $currentAction = Route::currentRouteAction();

        if (!$facilityId || !$userId || !$groupName) {

            // Public view routes - no session needed
            if (in_array($currentAction, self::PUBLIC_VIEW_ROUTES)) {
                return $next($request);
            }

            // API auth routes - facility resolved from request, not session
            if (in_array($currentAction, self::API_AUTH_ROUTES)) {
                return $this->handleApiAuthRequest($request, $next);
            }

            session()->invalidate();
            return $this->unauthenticatedResponse($request);
        }

        // Already logged in - redirect away from login page
        if (in_array($currentAction, self::PUBLIC_VIEW_ROUTES)) {
            return redirect(route('patient.add'));
        }

        // Configure tenant DB connection for this request
        try {
            FacilityService::configureTenant($facilityId);
        } catch (\RuntimeException $e) {
            Log::warning('Tenant configuration failed', ['facility_id' => $facilityId]);
            session()->invalidate();
            return $this->unauthenticatedResponse($request);
        }

        // Inject facility context into request so controllers don't re-fetch it
        $request->merge([
            'facility_id' => $facilityId,
            'entry_by'    => $userId,
        ]);

        return $next($request);
    }

    /**
     * Handle API login / password-update requests (no session exists yet).
     *
     * Resolves facility from the request body, configures the tenant DB,
     * then passes through to the AuthController.
     *
     * Security: if the facility is not found, a timing-safe dummy hash check
     * is performed before returning a generic 401. This ensures response time
     * is indistinguishable from a valid-facility / wrong-password response,
     * preventing timing-based facility enumeration attacks.
     */
    private function handleApiAuthRequest(Request $request, Closure $next)
    {
        $facilityName = $request->input('facility');
        $facilityInfo = null;

        try {
            $facilityInfo = FacilityService::validateFacility($facilityName);

            if ($facilityInfo) {
                FacilityService::configureTenant($facilityInfo['id']);
            }
        } catch (\Exception $e) {
            Log::error('Facility resolution failed during API auth', [
                'error' => $e->getMessage(),
            ]);
        }

        if (!$facilityInfo) {
            // Timing-safe: simulate the cost of a real password check
            // so invalid-facility responses take the same time as wrong-password ones
            Hash::check(
                $request->input('password', ''),
                config('mediboard.auth.dummy_hash')
            );

            return response()->json(config('mediboard.auth.fail_response'), 401);
        }

        return $next($request);
    }

    /**
     * Return a generic unauthenticated response.
     *
     * API requests (v1/*) receive an obfuscated JSON payload - error codes
     * and messages are intentionally generic to avoid leaking session or
     * facility state. Blade requests are redirected to /.
     */
    private function unauthenticatedResponse(Request $request)
    {
        if ($request->is('v1/*')) {
            return response()->json(config('mediboard.auth.fail_response'), 401);
        }

        return redirect('/');
    }
}