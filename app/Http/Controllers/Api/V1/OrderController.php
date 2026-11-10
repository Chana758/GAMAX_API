<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The logged-in customer's orders (/api/v1/orders). */
class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders)
    {
    }

    /** GET /orders?page=2 — newest first */
    public function index(Request $request): JsonResponse
    {
        $orders = $request->user()->orders()
            ->withSum('items', 'quantity')
            ->latest('id')
            ->paginate(min(max((int) $request->integer('per_page', 10), 1), 50));

        return response()->json([
            'success' => true,
            'data'    => OrderResource::collection($orders->items()),
            'meta'    => [
                'current_page' => $orders->currentPage(),
                'last_page'    => $orders->lastPage(),
                'per_page'     => $orders->perPage(),
                'total'        => $orders->total(),
            ],
        ]);
    }

    /** POST /orders/quote — totals for the review step, nothing is saved. */
    public function quote(CheckoutRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->orders->quote($request->user(), $request->validated()),
        ]);
    }

    /** POST /orders */
    public function store(CheckoutRequest $request): JsonResponse
    {
        $order = $this->orders->place($request->user(), $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Order placed.',
            'data'    => new OrderResource($order),
        ], Response::HTTP_CREATED);
    }

    /** GET /orders/{number} */
    public function show(Request $request, Order $order): JsonResponse
    {
        $this->ensureOwner($request, $order);

        return response()->json([
            'success' => true,
            'data'    => new OrderResource($order->load('items.product:id,slug')),
        ]);
    }

    /** POST /orders/{number}/cancel  { reason?: string } */
    public function cancel(Request $request, Order $order): JsonResponse
    {
        $this->ensureOwner($request, $order);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $order = $this->orders->cancelByCustomer($order, $data['reason'] ?? null);

        return response()->json([
            'success' => true,
            'message' => 'Order cancelled.',
            'data'    => new OrderResource($order),
        ]);
    }

    /** POST /orders/{number}/payment  { reference?: string } — "I've paid" for a KHQR order */
    public function reportPayment(Request $request, Order $order): JsonResponse
    {
        $this->ensureOwner($request, $order);
        $data = $request->validate(['reference' => ['nullable', 'string', 'max:100']]);

        $order = $this->orders->reportPayment($order, isset($data['reference']) ? trim($data['reference']) : null);

        return response()->json([
            'success' => true,
            'message' => 'Thank you! We will check your payment and confirm your order.',
            'data'    => new OrderResource($order),
        ]);
    }

    /** Someone else's order answers 404, so order numbers can't be probed. */
    private function ensureOwner(Request $request, Order $order): void
    {
        abort_if($order->user_id !== $request->user()->id, Response::HTTP_NOT_FOUND, 'Order not found.');
    }
}
