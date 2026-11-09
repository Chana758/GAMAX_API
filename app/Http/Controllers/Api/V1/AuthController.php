<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    // constructor 
    public function __construct(protected AuthService $authService)
    {
    }
    public function register(RegisterRequest $request):JsonResponse{

        [$user, $token] = $this->authService->register($request->validated());
        if(method_exists($user,'roles')){
            $user->load('roles');
        }
        return response()->json([
            'success' => true,
            'message' => 'Register succesfully !',
            'data'    => [
                        'user'  => new UserResource($user),
                        'token' => $token,
                    ],
        ],201);
    }

    public function login(LoginRequest $request):JsonResponse{
        
        [$user, $token] = $this->authService->login($request->validated());
 
        if (method_exists($user, 'roles')) {
            $user->load('roles');
        }
 
        return response()->json([
            'success' => true,
            'message' => 'Logged in successfully.',
            'data'    => [
                'user'  => new UserResource($user),
                'token' => $token,
            ],
        ]);
    }
    public function me(Request $request):JsonResponse{
        $user = $request->user();
         if (method_exists($user, 'roles')) {
            $user->load('roles');
        }
 
        return response()->json([
            'success' => true,
            'data'    => new UserResource($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());
 
        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.',
        ]);
    }
}
