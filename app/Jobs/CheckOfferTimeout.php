<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Services\DriverNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;


class CheckOfferTimeout implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 30;

    protected $booking;
    protected $round;

    
    public function __construct(Booking $booking, int $round)
    {
        $this->booking = $booking;
        $this->round = $round;
    }

    
    public function handle(DriverNotificationService $driverNotificationService): void
    {
        try {

            $this->booking->refresh();
            
            if ($this->booking->status !== 'searching' || $this->booking->driver_id) {

                return;
            }



            $driverNotificationService->handleOfferTimeout($this->booking, $this->round);

        } catch (\Exception $e) {
            throw $e;
        }
    }

    
    public function failed(\Throwable $exception): void
    {
        try {
            $driverNotificationService = app(DriverNotificationService::class);
            $driverNotificationService->handleOfferTimeout($this->booking, $this->round);
        } catch (\Exception $e) {
        }
    }
}
