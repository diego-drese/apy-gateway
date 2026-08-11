<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\VerifyTwoFactorCodeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyTwoFactorCodeRequest;
use Illuminate\Http\JsonResponse;

class TwoFactorChallengeController extends Controller
{
    public function store(VerifyTwoFactorCodeRequest $request, VerifyTwoFactorCodeAction $action): JsonResponse
    {
        $result = $action->handle($request->string('code')->toString(), $request->ip());

        return response()->json([
            'message' => 'Login realizado.',
            'token' => $result['token'],
        ]);
    }
}
