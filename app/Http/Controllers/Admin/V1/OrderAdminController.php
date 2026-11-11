<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecordPaymentRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Http\Resources\AdminOrderResource;
use App\Models\Order;
use App\Models\Payment;
use App\Services\OrderPaymentService;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** /api/v1/admin/orders — staff with order.view / order.update */
class OrderAdminController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly OrderPaymentService $payments,
    ) {
    }

    /**
     * GET /admin/orders?status=confirmed,processing&payment_status=&channel=&payment_method=
     *     &needs_refund=1&from=2026-11-01&to=2026-11-30&search=0123&sort=newest|oldest|total_desc|total_asc
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Order::class);

        $request->validate([
            'status'         => ['nullable', 'string', 'max:200'],   // one status or a comma list
            'needs_refund'   => ['nullable', 'boolean'],
            'payment_status' => ['nullable', Rule::in(Order::PAYMENT_STATUSES)],
            'channel'        => ['nullable', Rule::in(Order::CHANNELS)],
            'payment_method' => ['nullable', 'string', 'max:20'],
            'from'           => ['nullable', 'date'],
            'to'             => ['nullable', 'date', 'after_or_equal:from'],
            'search'         => ['nullable', 'string', 'max:100'],
            'per_page'       => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort'           => ['nullable', Rule::in(['newest', 'oldest', 'total_desc', 'total_asc'])],
        ]);

        $statuses = array_values(array_filter(explode(',', (string) $request->input('status'))));
        if (array_diff($statuses, Order::STATUSES)) {
            throw ValidationException::withMessages(['status' => 'Unknown order status.']);
        }

        $orders = Order::query()
            ->with('user:id,name,email,phone')
            ->withSum('items', 'quantity')
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->when($request->boolean('needs_refund'), fn ($q) => $q->needsRefund())
            ->when($request->input('payment_status'), fn ($q, $v) => $q->where('payment_status', $v))
            ->when($request->input('channel'), fn ($q, $v) => $q->where('channel', $v))
            ->when($request->input('payment_method'), fn ($q, $v) => $q->where('payment_method', $v))
            ->when($request->input('from'), fn ($q, $v) => $q->where('created_at', '>=', Carbon::parse($v)->startOfDay()))
            ->when($request->input('to'), fn ($q, $v) => $q->where('created_at', '<=', Carbon::parse($v)->endOfDay()))
            ->when(Str::squish((string) $request->input('search')), function ($q, $search) {
                $like = '%' . addcslashes(mb_strtolower($search), '%_\\') . '%';
                $phone = preg_replace('/[\s\-().]/', '', $search);

                $q->where(function ($q) use ($like, $phone) {
                    $q->whereRaw('LOWER(number) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(ship_name) LIKE ?', [$like])
                        ->orWhere('ship_phone', 'like', '%' . addcslashes($phone, '%_\\') . '%');
                });
            })
            ->when($request->input('sort', 'newest'), fn ($q, $sort) => match ($sort) {
                'oldest'     => $q->oldest('id'),
                'total_desc' => $q->orderByDesc('total')->latest('id'),
                'total_asc'  => $q->orderBy('total')->latest('id'),
                default      => $q->latest('id'),
            })
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

    /**
     * GET /admin/orders/summary — what needs someone's attention now.
     * needs_action is the number shown on the "Orders" menu.
     */
    public function summary(): JsonResponse
    {
        $this->authorize('viewAny', Order::class);

        // Same scopes as the list filters, so a count and its list always match
        $toCall = Order::toCall()->count();
        $paymentToCheck = Order::paymentToCheck()->count();
        $toRefund = Order::needsRefund()->count();
        $toShip = Order::toShip()->count();

        return response()->json([
            'success' => true,
            'data'    => [
                'to_call'          => $toCall,          // COD: call the customer
                'payment_to_check' => $paymentToCheck,  // KHQR: customer says paid, check the bank app
                'to_refund'        => $toRefund,        // overpaid, or cancelled after paying
                'to_ship'          => $toShip,          // confirmed, waiting to be packed / shipped
                'needs_action'     => $toCall + $paymentToCheck + $toRefund,
            ],
        ]);
    }

    /**
     * GET /admin/orders/stats — numbers for the owner at the top of the Orders page.
     * Month = this month so far, compared with the same days of last month (Nov 1–10 vs Oct 1–10).
     * "received" is real money in (payments − refunds), counted on the day it was recorded.
     */
    public function stats(): JsonResponse
    {
        $this->authorize('viewAny', Order::class);

        $tz = config('shop.timezone', 'Asia/Phnom_Penh');
        $now = now($tz);
        $today = $now->copy()->startOfDay();
        $month = $now->copy()->startOfMonth();
        $prevFrom = $month->copy()->subMonthNoOverflow();
        $prevTo = $prevFrom->copy()->addSeconds((int) $month->diffInSeconds($now));
        $prevTo = $prevTo->greaterThan($month) ? $month->copy() : $prevTo;   // e.g. Mar 31 vs a shorter Feb

        // Times are stored in the app timezone (UTC)
        $db = fn (Carbon $t) => $t->copy()->setTimezone(config('app.timezone'));

        $received = fn (Carbon $from, Carbon $to) => round((float) Payment::query()
            ->whereBetween('created_at', [$db($from), $db($to)])
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'refund' THEN -amount ELSE amount END), 0) AS net")
            ->value('net'), 2);

        $placed = function (Carbon $from, Carbon $to) use ($db) {
            $row = Order::query()
                ->where('status', '!=', Order::CANCELLED)
                ->whereBetween('created_at', [$db($from), $db($to)])
                ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total), 0) AS sales')
                ->first();

            return ['orders' => (int) $row->n, 'sales' => round((float) $row->sales, 2)];
        };

        $thisMonth = $placed($month, $now);
        $lastMonth = $placed($prevFrom, $prevTo);
        $avg = fn (array $p) => $p['orders'] > 0 ? round($p['sales'] / $p['orders'], 2) : 0.0;

        // Last 14 days, one number per day (small charts)
        $since = $today->copy()->subDays(13);
        $days = collect(range(0, 13))->map(fn ($i) => $since->copy()->addDays($i)->toDateString());
        $dayOf = fn ($t) => $t->copy()->setTimezone($tz)->toDateString();

        $moneyByDay = Payment::query()->where('created_at', '>=', $db($since))->get(['type', 'amount', 'created_at'])
            ->groupBy(fn ($p) => $dayOf($p->created_at))
            ->map(fn ($rows) => $rows->sum(fn ($p) => $p->type === Payment::TYPE_REFUND ? -$p->amount : $p->amount));
        $ordersByDay = Order::query()->where('status', '!=', Order::CANCELLED)->where('created_at', '>=', $db($since))
            ->get(['created_at'])->groupBy(fn ($o) => $dayOf($o->created_at))->map->count();

        $unpaid = Order::query()
            ->where('status', '!=', Order::CANCELLED)
            ->whereColumn('amount_paid', '<', 'total')
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total - amount_paid), 0) AS amount')
            ->first();

        return response()->json([
            'success' => true,
            'data'    => [
                'period'     => ['from' => $month->toDateString(), 'to' => $now->toDateString(), 'compared_to' => [$prevFrom->toDateString(), $prevTo->toDateString()]],
                'received'   => [
                    'month' => $received($month, $now),
                    'prev'  => $received($prevFrom, $prevTo),
                    'today' => $received($today, $now),
                    'daily' => $days->map(fn ($d) => round((float) ($moneyByDay[$d] ?? 0), 2))->values(),
                ],
                'orders'     => [
                    'month' => $thisMonth['orders'],
                    'prev'  => $lastMonth['orders'],
                    'today' => $placed($today, $now)['orders'],
                    'daily' => $days->map(fn ($d) => (int) ($ordersByDay[$d] ?? 0))->values(),
                ],
                'average'    => ['month' => $avg($thisMonth), 'prev' => $avg($lastMonth)],
                // Still owed on open orders (COD on the way, KHQR not paid, part paid)
                'to_collect' => ['amount' => round((float) $unpaid->amount, 2), 'orders' => (int) $unpaid->n],
            ],
        ]);
    }

    /** GET /admin/orders/{number} */
    public function show(Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        return $this->orderResponse($order);
    }

    /** PATCH /admin/orders/{number}  { status?, tracking_number?, admin_note?, cancel_reason? } */
    public function update(UpdateOrderRequest $request, Order $order): JsonResponse
    {
        $order = $this->orders->updateByStaff($order, $request->validated(), $request->user());

        return $this->orderResponse($order, 'Order updated.');
    }

    /** POST /admin/orders/{number}/payments — money received (permission payment.record) */
    public function storePayment(RecordPaymentRequest $request, Order $order): JsonResponse
    {
        $order = $this->payments->record($order, $request->validated(), $request->user());

        return $this->orderResponse($order, 'Payment recorded.', Response::HTTP_CREATED);
    }

    /** POST /admin/orders/{number}/refunds — money given back (permission payment.refund) */
    public function storeRefund(RecordPaymentRequest $request, Order $order): JsonResponse
    {
        $order = $this->payments->refund($order, $request->validated(), $request->user());

        return $this->orderResponse($order, 'Refund recorded.', Response::HTTP_CREATED);
    }

    private function orderResponse(Order $order, ?string $message = null, int $status = Response::HTTP_OK): JsonResponse
    {
        return response()->json([
            'success'          => true,
            'message'          => $message,
            'data'             => new AdminOrderResource($order->load([
                'items.product:id,slug', 'user:id,name,email,phone', 'payments.recorder:id,name', 'creator:id,name',
            ])),
            'customer_summary' => $this->customerSummary($order),
        ], $status);
    }

    /**
     * How often this customer buys: their account's orders, or (walk-in / guest) the same phone.
     * "spent" is money actually paid, cancelled orders left out.
     */
    private function customerSummary(Order $order): array
    {
        $orders = Order::query()->where('status', '!=', Order::CANCELLED)
            ->when($order->user_id, fn ($q) => $q->where('user_id', $order->user_id), fn ($q) => $q->where('ship_phone', $order->ship_phone));

        return [
            'orders' => (clone $orders)->count(),
            'spent'  => round((float) (clone $orders)->sum('amount_paid'), 2),
            'search' => $order->ship_phone,   // what to search in the list to see them
        ];
    }
}
