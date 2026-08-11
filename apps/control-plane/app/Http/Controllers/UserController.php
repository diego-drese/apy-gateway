<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\User\CreateUserAction;
use App\Actions\User\DeleteUserAction;
use App\Actions\User\UpdateUserAction;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class UserController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        return UserResource::collection(User::query()->paginate(15));
    }

    public function store(StoreUserRequest $request, CreateUserAction $action): JsonResponse
    {
        $this->authorize('create', User::class);

        $user = $action->handle(
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            $request->user(),
            $request->ip(),
        );

        return UserResource::make($user)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(User $user): UserResource
    {
        $this->authorize('view', $user);

        return UserResource::make($user);
    }

    public function update(UpdateUserRequest $request, User $user, UpdateUserAction $action): UserResource
    {
        $this->authorize('update', $user);

        $user = $action->handle($user, $request->validated(), $request->user(), $request->ip());

        return UserResource::make($user);
    }

    public function destroy(User $user, DeleteUserAction $action, Request $request): Response
    {
        $this->authorize('delete', $user);

        $action->handle($user, $request->user(), $request->ip());

        return response()->noContent();
    }
}
