<?php

namespace App\Console\Commands;

use App\Services\OrderService;
use Illuminate\Console\Command;

/** php artisan orders:cancel-unpaid — scheduled every 15 minutes in routes/console.php */
class CancelUnpaidOrders extends Command
{
    protected $signature = 'orders:cancel-unpaid';

    protected $description = 'Cancel KHQR orders not paid before their deadline and put their stock back';

    public function handle(OrderService $orders): int
    {
        $count = $orders->cancelOverdueUnpaid();
        $this->info("Cancelled {$count} unpaid order(s).");

        return self::SUCCESS;
    }
}
