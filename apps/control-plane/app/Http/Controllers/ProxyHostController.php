<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ProxyHost\CreateProxyHostAction;
use App\Actions\ProxyHost\DeleteProxyHostAction;
use App\Actions\ProxyHost\UpdateProxyHostAction;
use App\Http\Requests\ProxyHost\StoreProxyHostRequest;
use App\Http\Requests\ProxyHost\UpdateProxyHostRequest;
use App\Http\Resources\ProxyHostResource;
use App\Models\ProxyHost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProxyHostController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ProxyHost::class);

        return ProxyHostResource::collection(ProxyHost::query()->paginate(15));
    }

    public function store(StoreProxyHostRequest $request, CreateProxyHostAction $action): JsonResponse
    {
        $this->authorize('create', ProxyHost::class);

        $proxyHost = $action->handle($request->validated(), $request->user());

        return ProxyHostResource::make($proxyHost)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(ProxyHost $proxy_host): ProxyHostResource
    {
        $this->authorize('view', $proxy_host);

        return ProxyHostResource::make($proxy_host);
    }

    public function update(
        UpdateProxyHostRequest $request,
        ProxyHost $proxy_host,
        UpdateProxyHostAction $action,
    ): ProxyHostResource {
        $this->authorize('update', $proxy_host);

        $proxyHost = $action->handle($proxy_host, $request->validated(), $request->user());

        return ProxyHostResource::make($proxyHost);
    }

    public function destroy(ProxyHost $proxy_host, DeleteProxyHostAction $action, Request $request): Response
    {
        $this->authorize('delete', $proxy_host);

        $action->handle($proxy_host, $request->user());

        return response()->noContent();
    }
}
