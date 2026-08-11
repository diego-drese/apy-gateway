<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ApproveIpAccessRequestAction;
use App\Actions\Auth\RequestIpAccessAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StoreIpAccessRequestRequest;
use App\Models\IpAccessRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IpAccessRequestController extends Controller
{
    public function store(StoreIpAccessRequestRequest $request, RequestIpAccessAction $action): JsonResponse
    {
        $action->handle($request->string('email')->toString(), $request->ip());

        return response()->json([
            'message' => 'Solicitação registrada. Um administrador precisa aprovar seu acesso.',
        ], 201);
    }

    public function approve(
        IpAccessRequest $ipAccessRequest,
        Request $request,
        ApproveIpAccessRequestAction $action,
    ): View {
        $action->handle($ipAccessRequest, $request->ip());

        return view('auth.ip-requests.approved');
    }
}
