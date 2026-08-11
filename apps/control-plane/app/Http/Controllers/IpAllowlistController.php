<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\IpAllowlist\CreateIpAllowlistEntryAction;
use App\Actions\IpAllowlist\DeleteIpAllowlistEntryAction;
use App\Http\Requests\IpAllowlist\StoreIpAllowlistEntryRequest;
use App\Http\Resources\IpAllowlistEntryResource;
use App\Models\IpAllowlistEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class IpAllowlistController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', IpAllowlistEntry::class);

        return IpAllowlistEntryResource::collection(IpAllowlistEntry::query()->paginate(15));
    }

    public function store(StoreIpAllowlistEntryRequest $request, CreateIpAllowlistEntryAction $action): JsonResponse
    {
        $this->authorize('create', IpAllowlistEntry::class);

        $entry = $action->handle(
            $request->string('ip_address')->toString(),
            $request->string('label')->toString() ?: null,
            $request->date('expires_at'),
            $request->user(),
            $request->ip(),
        );

        return IpAllowlistEntryResource::make($entry)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function destroy(IpAllowlistEntry $ip_allowlist_entry, DeleteIpAllowlistEntryAction $action, Request $request): Response
    {
        $this->authorize('delete', $ip_allowlist_entry);

        $action->handle($ip_allowlist_entry, $request->user(), $request->ip());

        return response()->noContent();
    }
}
