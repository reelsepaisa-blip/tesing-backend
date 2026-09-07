<?php

namespace App\Filament\Resources\DriverIncentiveResource\Pages;

use Filament\Actions\ViewAction;
use Filament\Actions\DeleteAction;
use Carbon\Carbon;
use Exception;
use App\Filament\Resources\DriverIncentiveResource;
use Filament\Actions;
use App\Filament\Resources\Pages\EditRecord;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

class EditDriverIncentive extends EditRecord
{
    protected static string $resource = DriverIncentiveResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->requiresConfirmation()
                ->action(function ($record) {
                    // Block deletion for restricted users (ID 2)
                    $userId = auth()->id();
                    if ($userId === 2) {
                        Notification::make()
                            ->title('Access Restricted')
                            ->body('In demo mode you are not deleting data...')
                            ->danger()
                            ->persistent()
                            ->send();
                        return false;
                    }

                    // Proceed with normal deletion
                    $record->delete();

                    Notification::make()
                        ->title('Deleted')
                        ->body('The driver incentive has been deleted.')
                        ->success()
                        ->send();

                    return redirect($this->getResource()::getUrl('index'));
                }),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Populate flat criteria fields from criteria array for editing
        $criteria = $data['criteria'] ?? [];
        $type = $data['type'] ?? null;

        switch ($type) {
            case 'ride_count':
                $data['criteria_target'] = $criteria['target'] ?? '';
                break;
            case 'streak':
                $data['criteria_consecutive_days'] = $criteria['consecutive_days'] ?? '';
                break;
            case 'time_based':
                $data['criteria_hours'] = $criteria['hours'] ?? '';
                $data['criteria_period'] = $criteria['period'] ?? '';
                break;
            case 'earnings':
                $data['criteria_target_amount'] = $criteria['target_amount'] ?? '';
                break;
            case 'custom':
                // criteria field is already set, no need to transform
                break;
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Validate and convert flat criteria fields to criteria array based on type
        $type = $data['type'] ?? null;
        $criteria = [];

        switch ($type) {
            case 'ride_count':
                if (empty($data['criteria_target'])) {
                    throw new ValidationException(
                        validator([], []),
                        ['criteria_target' => ['Target rides is required for ride count incentive.']]
                    );
                }
                $criteria['target'] = (int)$data['criteria_target'];
                if ($criteria['target'] < 1) {
                    throw new ValidationException(
                        validator([], []),
                        ['criteria_target' => ['Target rides must be at least 1.']]
                    );
                }
                break;
            case 'streak':
                if (empty($data['criteria_consecutive_days'])) {
                    throw new ValidationException(
                        validator([], []),
                        ['criteria_consecutive_days' => ['Consecutive days is required for streak incentive.']]
                    );
                }
                $criteria['consecutive_days'] = (int)$data['criteria_consecutive_days'];
                if ($criteria['consecutive_days'] < 1) {
                    throw new ValidationException(
                        validator([], []),
                        ['criteria_consecutive_days' => ['Consecutive days must be at least 1.']]
                    );
                }
                break;
            case 'time_based':
                if (empty($data['criteria_hours'])) {
                    throw new ValidationException(
                        validator([], []),
                        ['criteria_hours' => ['Target hours is required for time-based incentive.']]
                    );
                }
                if (empty($data['criteria_period'])) {
                    throw new ValidationException(
                        validator([], []),
                        ['criteria_period' => ['Period is required for time-based incentive.']]
                    );
                }
                $criteria['hours'] = (int)$data['criteria_hours'];
                if ($criteria['hours'] < 1) {
                    throw new ValidationException(
                        validator([], []),
                        ['criteria_hours' => ['Target hours must be at least 1.']]
                    );
                }
                $criteria['period'] = $data['criteria_period'];
                break;
            case 'earnings':
                if (empty($data['criteria_target_amount'])) {
                    throw new ValidationException(
                        validator([], []),
                        ['criteria_target_amount' => ['Target earnings is required for earnings incentive.']]
                    );
                }
                $criteria['target_amount'] = (float)$data['criteria_target_amount'];
                if ($criteria['target_amount'] < 1) {
                    throw new ValidationException(
                        validator([], []),
                        ['criteria_target_amount' => ['Target earnings must be at least ₹1.']]
                    );
                }
                break;
            case 'custom':
                if (empty($data['criteria']) || !is_array($data['criteria']) || count($data['criteria']) === 0) {
                    throw new ValidationException(
                        validator([], []),
                        ['criteria' => ['At least one custom criteria is required for custom incentive.']]
                    );
                }
                $criteria = $data['criteria'];
                break;
        }

        $data['criteria'] = $criteria;

        // Validate milestone rewards don't exceed basic reward amount
        if (!empty($data['milestones']) && is_array($data['milestones'])) {
            $totalMilestoneRewards = 0;
            foreach ($data['milestones'] as $milestone) {
                $reward = $milestone['reward'] ?? 0;
                $totalMilestoneRewards += (float)$reward;
            }

            $basicRewardAmount = (float)($data['reward_amount'] ?? 0);

            if ($totalMilestoneRewards > $basicRewardAmount) {
                Notification::make()
                    ->title('Validation Error')
                    ->body("The total milestone rewards (₹" . number_format($totalMilestoneRewards, 2) . ") cannot exceed the basic reward amount (₹" . number_format($basicRewardAmount, 2) . ").")
                    ->danger()
                    ->persistent()
                    ->send();
                
                throw ValidationException::withMessages([
                    'milestones' => "The total milestone rewards (₹" . number_format($totalMilestoneRewards, 2) . ") cannot exceed the basic reward amount (₹" . number_format($basicRewardAmount, 2) . ")."
                ]);
            }
        }
        
        // Normalize time_slots format if present
        if (!empty($data['time_slots']) && is_array($data['time_slots'])) {
            foreach ($data['time_slots'] as &$slot) {
                // Ensure time format is consistent (HH:MM)
                if (isset($slot['start'])) {
                    $slot['start'] = $this->normalizeTime($slot['start']);
                }
                if (isset($slot['end'])) {
                    $slot['end'] = $this->normalizeTime($slot['end']);
                }
            }
            unset($slot);
        }

        // Remove temporary fields
        unset($data['criteria_target'], $data['criteria_consecutive_days'], $data['criteria_hours'], $data['criteria_period'], $data['criteria_target_amount']);

        return $data;
    }
    
    /**
     * Normalize time format to HH:MM
     */
    protected function normalizeTime($time): string
    {
        if (empty($time)) {
            return '';
        }
        
        // If it's already in HH:MM format, return as is
        if (preg_match('/^\d{2}:\d{2}$/', $time)) {
            return $time;
        }
        
        // If it's in HH:MM:SS format, remove seconds
        if (preg_match('/^(\d{2}:\d{2}):\d{2}$/', $time, $matches)) {
            return $matches[1];
        }
        
        // Try to parse as Carbon and format
        try {
            $carbon = Carbon::parse($time);
            return $carbon->format('H:i');
        } catch (Exception $e) {
            return $time;
        }
    }
}

