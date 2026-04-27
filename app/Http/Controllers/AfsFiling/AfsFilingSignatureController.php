<?php

declare(strict_types=1);

namespace App\Http\Controllers\AfsFiling;

use App\Http\Controllers\Controller;
use App\Http\Requests\AfsFiling\AfsFilingSignatureStoreRequest;
use App\Http\Requests\AfsFiling\AfsFilingSignatureUpdateRequest;
use App\Models\User;
use App\Services\AfsFiling\AfsFilingSignatureService;
use App\Services\SignatureImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AfsFilingSignatureController extends Controller
{
    public function index(Request $request, AfsFilingSignatureService $signatureService): JsonResponse
    {
        $signatureService->ensureFeatureEnabledOr404();

        /** @var User $user */
        $user = $request->user();

        return response()->json($signatureService->payload($user));
    }

    public function store(
        AfsFilingSignatureStoreRequest $request,
        AfsFilingSignatureService $signatureService,
        SignatureImageService $signatureImageService,
    ): JsonResponse {
        $signatureService->ensureFeatureEnabledOr404();

        /** @var User $user */
        $user = $request->user();

        return response()->json($signatureService->store(
            $user,
            $request->validated(),
            $request->file('signature_file'),
            $signatureImageService,
        ));
    }

    public function update(
        AfsFilingSignatureUpdateRequest $request,
        AfsFilingSignatureService $signatureService,
        SignatureImageService $signatureImageService,
    ): JsonResponse {
        $signatureService->ensureFeatureEnabledOr404();

        /** @var User $user */
        $user = $request->user();

        return response()->json($signatureService->update(
            $user,
            $request->validated(),
            $request->file('signature_file'),
            $signatureImageService,
        ));
    }

    public function destroy(Request $request, AfsFilingSignatureService $signatureService): JsonResponse
    {
        $signatureService->ensureFeatureEnabledOr404();

        /** @var User $user */
        $user = $request->user();

        return response()->json($signatureService->destroy($user));
    }

    public function preview(Request $request, AfsFilingSignatureService $signatureService): StreamedResponse
    {
        $signatureService->ensureFeatureEnabledOr404();

        /** @var User $user */
        $user = $request->user();

        return $signatureService->preview($user);
    }
}
