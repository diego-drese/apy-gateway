<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Auth\ApproveIpAccessRequestAction;
use App\Enums\IpAccessRequestStatus;
use App\Http\Resources\IpAccessRequestResource;
use App\Http\Resources\IpAllowlistEntryResource;
use App\Models\IpAccessRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Authenticated, Policy-gated IP access request management for the web UI — distinct from
 * App\Http\Controllers\Auth\IpAccessRequestController, which is the public, unauthenticated,
 * token-based endpoint pair from Fase 2 (no middleware, no Policy, security model is
 * "possession of a single-use token"). Mixing the two security contexts into one class would
 * mean half its methods must never have `$this->authorize()` and half always must — two small
 * unambiguous classes are safer than one that has to remember which half is which.
 */
class IpAccessRequestController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', IpAccessRequest::class);

        $pending = IpAccessRequest::query()
            ->where('status', IpAccessRequestStatus::Pending)
            ->paginate(15);

        return IpAccessRequestResource::collection($pending);
    }

    public function approve(
        IpAccessRequest $ip_access_request,
        Request $request,
        ApproveIpAccessRequestAction $action,
    ): IpAllowlistEntryResource {
        $this->authorize('approve', $ip_access_request);

        $entry = $action->handle($ip_access_request, $request->ip(), $request->user());

        return IpAllowlistEntryResource::make($entry);
    }
}
