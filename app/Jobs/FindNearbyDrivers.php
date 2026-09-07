<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;


class FindNearbyDrivers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;
    public $maxExceptions = 3;
    public $timeout = 60;
    public $backoff = [5, 10, 20, 30, 60]; // Retry delays in seconds

    protected $booking;
    protected $searchRadius;
    protected $maxDrivers;
    protected $waitTime;
    protected $attempt;

    public function __construct(Booking $booking, int $searchRadius = 5000, int $maxDrivers = 5, int $waitTime = 30, int $attempt = 1)
    {
        $this->booking = $booking;
        $this->searchRadius = $searchRadius;
        $this->maxDrivers = $maxDrivers;
        $this->waitTime = $waitTime;
        $this->attempt = $attempt;
    }

    public function handle(): void
    {

        if (!$this->isBookingValid()) {

            return;
        }

        try {


            $drivers = $this->findNearbyDrivers();


            if ($drivers->isEmpty()) {
                $this->handleNoDriversFound();
                return;
            }

            $drivers = $this->prioritizeDrivers($drivers);

            $this->notifyDrivers($drivers);
        } catch (\Exception $e) {
            throw $e;
        }
    }

    protected function isBookingValid(): bool
    {

        $this->booking->refresh();

        return $this->booking->status === 'searching' &&
            !$this->booking->driver_id &&
            (!$this->booking->scheduled_at || $this->booking->scheduled_at->isPast());
    }

    protected function findNearbyDrivers(): \Illuminate\Support\Collection
    {

        $bookingUser = $this->booking->user;

        $query = User::query()
            ->drivers()
            ->active()
            ->online()
            ->whereNull('current_booking_id')
            ->whereHas('driverProfile', function ($query) {
                $query->where('city_id', $this->booking->pickupZone->city_id);
            })
            ->whereHas('vehicles', function ($query) {
                $query->where('ride_type_id', $this->booking->ride_type_id)
                    ->where('status', 'active');
            })
            ->whereHas('currentLocation', function ($query) {
                $query->withinRadius(
                    $this->booking->pickup_location,
                    $this->searchRadius * ($this->attempt / 5) // Increase radius with each attempt
                );
            });

        if ($bookingUser) {
            $query->where(function ($q) use ($bookingUser) {

                if (!empty($bookingUser->phone)) {
                    $q->where('phone', '!=', $bookingUser->phone);
                }

                if (!empty($bookingUser->email)) {
                    $q->where(function ($emailQuery) use ($bookingUser) {
                        $emailQuery->whereNull('email')
                            ->orWhere('email', '!=', $bookingUser->email);
                    });
                }
            });
        }

        return $query->with(['driverProfile', 'currentLocation', 'vehicles'])->get();
    }

    protected function prioritizeDrivers($drivers): \Illuminate\Support\Collection
    {

        $scoringService = app(\App\Services\AdvancedDriverScoringService::class);

        $isFallback = $drivers->some(function ($driver) {
            return $driver->current_booking_id !== null;
        });


        $rankedDrivers = $isFallback
            ? $scoringService->scoreFallbackDrivers($drivers, $this->booking)
            : $scoringService->scoreIdleDrivers($drivers, $this->booking);


        return $rankedDrivers;
    }

    protected function notifyDrivers($drivers): void
    {
        $cacheKey = "booking_{$this->booking->id}_notified_drivers";
        $notifiedDrivers = Cache::get($cacheKey, []);

        foreach ($drivers as $driver) {

            if (in_array($driver->id, $notifiedDrivers)) {
                continue;
            }

            $notifiedDrivers[] = $driver->id;
            Cache::put($cacheKey, $notifiedDrivers, now()->addMinutes(30));

            $this->notifyDriver($driver);

            if (count($notifiedDrivers) >= $this->maxDrivers) {
                break;
            }
        }

        if ($this->attempt < 5) {
            self::dispatch($this->booking, $this->searchRadius, $this->maxDrivers, $this->waitTime, $this->attempt + 1)
                ->delay(now()->addSeconds($this->waitTime));
        } else {
            $this->handleNoDriversAccepted();
        }
    }

    protected function notifyDriver(User $driver): void
    {

        $offerKey = "booking_offer_{$this->booking->id}_{$driver->id}";
        Cache::put($offerKey, true, now()->addSeconds($this->waitTime));
    }

    protected function handleNoDriversFound(): void
    {
        if ($this->attempt >= 5) {
            $this->booking->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => 'No drivers available',
                'cancelled_by_type' => 'system',
            ]);
        } else {

            self::dispatch($this->booking, $this->searchRadius * 1.5, $this->maxDrivers, $this->waitTime, $this->attempt + 1)
                ->delay(now()->addSeconds($this->waitTime));
        }
    }

    protected function handleNoDriversAccepted(): void
    {
        $this->booking->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => 'No drivers accepted the request',
            'cancelled_by_type' => 'system',
        ]);
    }
}
