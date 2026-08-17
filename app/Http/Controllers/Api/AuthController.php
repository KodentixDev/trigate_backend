<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;
use Kreait\Laravel\Firebase\Facades\Firebase;

class AuthController extends Controller
{
    /**
     * Verify a Firebase ID token (issued after the mobile app completes
     * Google, Apple, or Play Games sign-in via the Firebase client SDK)
     * and exchange it for a Sanctum API token.
     */
    public function socialLogin(Request $request): JsonResponse
    {
        $request->validate([
            'id_token' => ['required', 'string'],
        ]);

        try {
            $verifiedToken = Firebase::auth()->verifyIdToken($request->string('id_token')->toString());
        } catch (FailedToVerifyToken $e) {
            throw ValidationException::withMessages([
                'id_token' => 'The provided Firebase ID token could not be verified.',
            ])->status(401);
        }

        $claims = $verifiedToken->claims();

        $user = User::updateOrCreate(
            ['firebase_uid' => $claims->get('sub')],
            [
                'name' => $claims->get('name'),
                'email' => $claims->get('email'),
                'email_verified_at' => $claims->get('email_verified') ? now() : null,
                'avatar_url' => $claims->get('picture'),
                'auth_provider' => data_get($claims->get('firebase'), 'sign_in_provider', 'unknown'),
            ]
        );

        return response()->json([
            'token' => $user->createToken('mobile')->plainTextToken,
            'user' => $user,
        ]);
    }

    /**
     * Return the currently authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }

    /**
     * Revoke the Sanctum token used for the current request.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
