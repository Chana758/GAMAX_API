<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddressRequest;
use App\Http\Resources\AddressResource;
use App\Models\Address;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** The logged-in customer's saved delivery addresses (/api/v1/addresses). */
class AddressController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => AddressResource::collection($request->user()->addresses()->get()),
        ]);
    }

    public function store(AddressRequest $request): JsonResponse
    {
        $user = $request->user();

        $address = DB::transaction(function () use ($request, $user) {
            $data = $request->validated();
            // The first address is the default one
            $data['is_default'] = ($data['is_default'] ?? false) || ! $user->addresses()->exists();

            $address = $user->addresses()->create($data);
            $this->keepOneDefault($user, $address);

            return $address;
        });

        return response()->json([
            'success' => true,
            'message' => 'Address saved.',
            'data'    => new AddressResource($address->fresh()),
        ], Response::HTTP_CREATED);
    }

    public function update(AddressRequest $request, Address $address): JsonResponse
    {
        $this->ensureOwner($request, $address);

        DB::transaction(function () use ($request, $address) {
            $data = $request->validated();
            // The only default address can't be un-defaulted (there must always be one)
            if ($address->is_default && array_key_exists('is_default', $data) && ! $data['is_default']) {
                unset($data['is_default']);
            }
            $address->update($data);
            $this->keepOneDefault($request->user(), $address);
        });

        return response()->json([
            'success' => true,
            'message' => 'Address updated.',
            'data'    => new AddressResource($address->fresh()),
        ]);
    }

    public function destroy(Request $request, Address $address): JsonResponse
    {
        $this->ensureOwner($request, $address);

        DB::transaction(function () use ($request, $address) {
            $wasDefault = $address->is_default;
            $address->delete();

            // Deleting the default address: the oldest remaining one becomes the default
            if ($wasDefault) {
                $request->user()->addresses()->reorder()->orderBy('id')->first()?->update(['is_default' => true]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Address removed.',
        ]);
    }

    /** Someone else's address answers 404, so ids can't be probed. */
    private function ensureOwner(Request $request, Address $address): void
    {
        abort_if($address->user_id !== $request->user()->id, Response::HTTP_NOT_FOUND, 'Address not found.');
    }

    private function keepOneDefault(User $user, Address $address): void
    {
        if ($address->is_default) {
            $user->addresses()->reorder()->whereKeyNot($address->id)->where('is_default', true)->update(['is_default' => false]);
        }
    }
}
