<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Certificate\RequestCertificateIssuanceAction;
use App\Actions\Certificate\UploadCertificateAction;
use App\Http\Requests\Certificate\RequestAcmeCertificateRequest;
use App\Http\Requests\Certificate\UploadCertificateRequest;
use App\Http\Resources\SslCertificateResource;
use App\Models\SslCertificate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CertificateController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SslCertificate::class);

        return SslCertificateResource::collection(SslCertificate::query()->paginate(15));
    }

    public function show(SslCertificate $certificate): SslCertificateResource
    {
        $this->authorize('view', $certificate);

        return SslCertificateResource::make($certificate);
    }

    public function upload(UploadCertificateRequest $request, UploadCertificateAction $action): JsonResponse
    {
        $this->authorize('create', SslCertificate::class);

        $certificate = $action->handle(
            $request->string('certificate')->toString(),
            $request->string('private_key')->toString(),
            $request->user(),
        );

        return SslCertificateResource::make($certificate)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function requestAcme(RequestAcmeCertificateRequest $request, RequestCertificateIssuanceAction $action): JsonResponse
    {
        $this->authorize('create', SslCertificate::class);

        $certificate = $action->handle($request->array('domain_names'), $request->user());

        // 202, not 201 — unlike upload(), the resource isn't complete yet (status stays
        // `pending` until the queued ACME job finishes); the client polls GET /certificates/{id}.
        return SslCertificateResource::make($certificate)
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }
}
