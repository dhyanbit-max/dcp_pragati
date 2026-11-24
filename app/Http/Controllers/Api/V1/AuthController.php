<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Role;
use App\Domain\Auth\OtpService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

    public function requestOtp(Request $request): Response
    {
        $data = $request->validate(['mobile' => ['required', 'regex:/^[6-9]\d{9}$/']]);
        $this->otp->request($data['mobile']);

        return response()->noContent(202);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mobile' => ['required', 'regex:/^[6-9]\d{9}$/'],
            'otp' => ['required', 'digits:'.config('dcp.otp.length', 6)],
            'device_id' => ['required', 'string', 'max:100'],
            'app_version' => ['nullable', 'string', 'max:20'],
        ]);

        $user = $this->otp->verify($data['mobile'], $data['otp']);
        if (! $user) {
            return response()->json(['error' => ['code' => 'OTP_INVALID', 'message' => __('auth.failed')]], 422);
        }

        $expiresAt = now()->addDays((int) config('dcp.field_token_days', 30));
        $abilities = $user->hasRole(Role::FIELD_USER) ? ['field'] : ['*'];
        $token = $user->createToken($data['device_id'], $abilities, $expiresAt);

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'user' => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->userPayload($request->user()));
    }

    private function userPayload($user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'mobile' => $user->mobile,
            'designation' => $user->designation,
            'locale' => $user->preferred_locale,
            'roles' => $user->getRoleNames(),
            'jurisdictions' => $user->jurisdictions()->get(['level', 'district_id', 'co_district_id', 'implementing_agency_id']),
        ];
    }
}
