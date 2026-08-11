<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\AttemptLoginAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\JsonResponse;

class LoginController extends Controller
{
    public function store(LoginRequest $request, AttemptLoginAction $action): JsonResponse
    {
        $action->handle(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->ip(),
        );

        return response()->json([
            'message' => 'Verifique seu e-mail para o código de verificação.',
            'two_factor_required' => true,
        ]);
    }
}
