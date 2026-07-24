<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Models\Organization;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $organizationId = $request->header('X-Organization-ID');
        $branchId = $request->header('X-Branch-ID');
        abort_if(blank($organizationId), 400, 'X-Organization-ID header is required.');
        abort_if(blank($branchId), 400, 'X-Branch-ID header is required.');

        $membership = $request->user()->organizations()
            ->whereKey($organizationId)
            ->first();
        abort_unless($membership instanceof Organization, 403, 'You do not belong to this organization.');

        $branch = $request->user()->branches()
            ->whereKey($branchId)
            ->where('organization_id', $membership->id)
            ->where('active', true)
            ->first();
        abort_unless($branch instanceof Branch, 403, 'You do not have access to this branch.');

        app()->instance(TenantContext::class, new TenantContext($membership, $branch, $membership->pivot->role));
        $request->attributes->set('organization', $membership);
        $request->attributes->set('branch', $branch);

        return $next($request);
    }
}
