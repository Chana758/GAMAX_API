<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateOrderRequest;
use App\Http\Resources\AdminOrderResource;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** /api/v1/admin/orders — staff with order.view / order.update */
class OrderAdminController extends Controller
{
    public function __construct(private readonly OrderService $orders)
    {
    }

    /** GET /admin/orders?status=pending_confirmation&search=0123&page=1&per_page=20 */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Order::class);

        $request->validate([
            'status'   => ['nullable', Rule::in(Order::STATUSES)],
            'search'   => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $orders = Order::query()
            ->with('user:id,name,email,phone')
            ->withSum('items', 'quantity')
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->when(Str::squish((string) $request->input('search')), function ($q, $search) {
                $like = '%' . addcslashes(mb_strtolower($search), '%_\\') . '%';
                $phone = preg_replace('/[\s\-().]/', '', $search);

                $q->where(function ($q) use ($like, $phone) {
                    $q->whereRaw('LOWER(number) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(ship_name) LIKE ?', [$like])
                        ->orWhere('ship_phone', 'like', '%' . addcslashes($phone, '%_\\') . '%');
                });
            })
            ->latest('id')
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'success' => true,
            'data'    => AdminOrderResource::collection($orders->items()),
            'meta'    => [
                'current_page' => $orders->currentPage(),
                'last_page'    => $orders->lastPage(),
                'per_page'     => $orders->perPage(),
                'total'        => $orders->total(),
                // Badge counts for the status tabs
                'status_counts' => Order::query()->selectRaw('status, COUNT(*) as total')
                    ->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n),
            ],
        ]);
    }

    /** GET /admin/orders/{number} */
    public function show(Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        return response()->json([
            'success' => true,
            'data'    => new AdminOrderResource($order->load(['items.product:id,slug', 'user:id,name,email,phone'])),
        ]);
    }

    /** PATCH /admin/orders/{number}  { status?, tracking_number?, payment_status?, admin_note?, cancel_reason? } */
    public function update(UpdateOrderRequest $request, Order $order): JsonResponse
    {
        $order = $this->orders->updateByStaff($order, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Order updated.',
            'data'    => new AdminOrderResource($order),
        ]);
    }
}
