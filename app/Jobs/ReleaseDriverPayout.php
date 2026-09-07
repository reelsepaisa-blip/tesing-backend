<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Services\PaymentGatewayService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;


class ReleaseDriverPayout implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $bookingId,
        public float $amount,
        public ?string $paymentMethod = null
    ) {
    }

    public function handle(PaymentGatewayService $paymentGatewayService): void
    {
        $booking = Booking::find($this->bookingId);

        if (!$booking) {
            
            return;
        }

        if ($booking->driver_payout_status === Booking::DRIVER_PAYOUT_COMPLETED) {
            
            return;
        }

        $paymentGatewayService->releaseDriverPayoutNow($booking, $this->amount, $this->paymentMethod);
    }
}

