<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

/** Staff access (admin routes). Customers only reach their own orders through OrderController. */
class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('order.view');
    }

    public function view(User $user, Order $order): bool
    {
        return $user->can('order.view');
    }

    public function update(User $user, Order $order): bool
    {
        return $user->can('order.update');
    }
}
