<?php

namespace App\Services;

use App\Models\User;
use GuzzleHttp\Promise\Create;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService {
    // The $data passed to register() must be an array and :array → This method must return an array
    public function register(array $data): array 
    {
        $user = User::Create([
            'name'      => $data['name'],
            'email'     => $data['email'],
            'phone'     => $data['phone']?? null,
            'password'  => Hash::make($data['password']),
        ]);
        if(method_exists($user,'assignRole')){
            $user -> assignRole('customer');
        }
        $token = $user->createToken('auth_token')->plainTextToken;
        return [$user, $token];
    }

    public function login(array $credentails):array
    {
        $user = User::where('email',$credentails['email'])->first();
        if(! $user || !Hash::check($credentails['password'],$user->password)){
            throw ValidationException::withMessages([
                'email' => ['Incorrect email or password.'],
            ]);
        }
    
        if (! $user->is_active){
            throw ValidationException::withMessages([
                'email' => ['Your account has been suspended. Please contact support.'],
            ]);
        }
        
        $token = $user->createToken('auth_token')->plainTextToken;
        return [$user,$token];

    }

    public function logout(User $user):void{ // no return value
        // Revoke only the token used for this request (other devices stay logged in)
        $user->currentAccessToken()->delete();
    }
} 