<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Group;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use App\Models\PaymentMethod;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\DatePicker;
use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\BookingResource\Pages\ListBookings;
use App\Filament\Resources\BookingResource\Pages\CreateBooking;
use App\Filament\Resources\BookingResource\Pages\ViewBooking;
use App\Filament\Resources\BookingResource\Pages\EditBooking;
use Exception;
use App\Events\BookingStatusChanged;
use App\Filament\Resources\BookingResource\Pages;
use App\Forms\Components\GooglePlacesAutocomplete;
use App\Models\Booking;
use App\Models\User;
use App\Services\BookingService;
use App\Services\DriverMatchingService;
use App\Services\PaymentGatewayService;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Forms;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

use Illuminate\Support\Str;

class BookingResource extends BaseResource
{
    protected static ?string $model = Booking::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Location')
                    ->schema([
                        Select::make('pickup_zone_id')
                            ->relationship('pickupZone', 'name', function ($query) {
                                return $query->active();  // Only show active zones
                            })
                            ->getOptionLabelFromRecordUsing(fn($record) => $record->name ?? 'Unknown')
                            ->required()
                            ->columnSpan(1),
                        Select::make('dropoff_zone_id')
                            ->relationship('dropoffZone', 'name', function ($query) {
                                return $query->active();  // Only show active zones
                            })
                            ->getOptionLabelFromRecordUsing(fn($record) => $record->name ?? 'Unknown')
                            ->required()
                            ->columnSpan(1),
                        GooglePlacesAutocomplete::make('pickup_address')
                            ->zoneField('pickup_zone_id')
                            ->locationField('pickup_location')
                            ->required()
                            ->columnSpanFull(),
                        GooglePlacesAutocomplete::make('dropoff_address')
                            ->zoneField('dropoff_zone_id')
                            ->locationField('dropoff_location')
                            ->required()
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Group::make()
                    ->schema([
                        Section::make('Booking Details')
                            ->schema([
                                Hidden::make('booking_code'),
                                Select::make('user_id')
                                    ->relationship('user', 'name', function ($query) {
                                        return $query
                                            ->where('role_id', 3)  // Filter for users only
                                            ->active()  // Only show active users
                                            ->selectRaw('id, COALESCE(NULLIF(name, ""), "unknown") as name, phone, role_id');
                                    })
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->columnSpan(1),
                                Select::make('driver_id')
                                    ->relationship('driver', 'name', function ($query) {
                                        return $query
                                            ->where('role_id', 2)  // Filter for drivers only
                                            ->active()  // Only show active drivers
                                            ->selectRaw('id, COALESCE(NULLIF(name, ""), "unknown") as name, phone, role_id');
                                    })
                                    ->searchable()
                                    ->preload()
                                    ->columnSpan(1),
                                Select::make('ride_type_id')
                                    ->relationship('rideType', 'name', function ($query) {
                                        return $query->active();  // Only show active ride types
                                    })
                                    ->required()
                                    ->columnSpan(1),
                                Select::make('status')
                                    ->options([
                                        'pending' => 'Pending',
                                        'searching' => 'Searching',
                                        'accepted' => 'Accepted',
                                        'arrived' => 'Arrived',
                                        'started' => 'Started',
                                        'completed' => 'Completed',
                                        'cancelled' => 'Cancelled',
                                        'expired' => 'Expired',
                                    ])
                                    ->required()
                                    ->columnSpan(1),
                                Textarea::make('cancellation_reason')
                                    ->label('Cancellation Reason')
                                    ->visible(fn($record) => $record && $record->status === 'cancelled')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->helperText('The reason why this booking was cancelled')
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),
                    ])
                    ->columnSpan(['lg' => 2]),
                Group::make()
                    ->schema([
                        Section::make('Payment')
                            ->schema([
                                Select::make('payment_method')
                                    ->options(function ($record) {
                                        $paymentMethods = PaymentMethod::ordered()
                                            ->get()
                                            ->mapWithKeys(function ($method) {
                                                return [$method->code => $method->name];
                                            })
                                            ->toArray();

                                        if ($record && $record->payment_method && !isset($paymentMethods[$record->payment_method])) {
                                            $paymentMethods[$record->payment_method] = ucfirst(str_replace('_', ' ', $record->payment_method));
                                        }

                                        if (empty($paymentMethods)) {
                                            return [
                                                'cash' => 'Cash',
                                                'wallet' => 'Wallet',
                                                'online' => 'Online',
                                                'split' => 'Split Payment',
                                            ];
                                        }

                                        return $paymentMethods;
                                    })
                                    ->placeholder('Select payment method')
                                    ->nullable()
                                    ->searchable()
                                    ->columnSpan(1),
                                Select::make('payment_status')
                                    ->options([
                                        'pending' => 'Pending',
                                        'paid' => 'Paid',
                                        'failed' => 'Failed',
                                        'refunded' => 'Refunded',
                                    ])
                                    ->required()
                                    ->columnSpan(1),
                                TextInput::make('total_amount')
                                    ->label('Total Amount')
                                    ->numeric()
                                    ->prefix('₹')
                                    ->step(0.01)
                                    ->placeholder('0.00')
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),
                    ])
                    ->columnSpan(['lg' => 1]),
            ])
            ->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordUrl(null)  // Disable row click navigation
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('booking_code')
                    ->searchable(),
                TextColumn::make('user.name')
                    ->searchable(),
                TextColumn::make('driver.name')
                    ->searchable(),
                TextColumn::make('rideType.name')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'pending' => 'gray',
                        'searching' => 'info',
                        'accepted' => 'warning',
                        'arrived' => 'warning',
                        'started' => 'warning',
                        'completed' => 'success',
                        'cancelled' => 'danger',
                        'expired' => 'danger',
                    }),
                TextColumn::make('cancellation_reason')
                    ->label('Cancellation Reason')
                    ->visible(fn(?Booking $record): bool => $record && $record->status === 'cancelled')
                    ->wrap()
                    ->tooltip(fn(?Booking $record): ?string => $record?->cancellation_reason),
                TextColumn::make('payment_status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'pending' => 'warning',
                        'paid' => 'success',
                        'failed' => 'danger',
                        'refunded' => 'info',
                    }),
                TextColumn::make('total_amount')
                    ->money('INR')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'searching' => 'Searching',
                        'accepted' => 'Accepted',
                        'arrived' => 'Arrived',
                        'started' => 'Started',
                        'completed' => 'Completed',
                        'cancelled' => 'Cancelled',
                        'expired' => 'Expired',
                    ]),
                SelectFilter::make('payment_status')
                    ->options([
                        'pending' => 'Pending',
                        'paid' => 'Paid',
                        'failed' => 'Failed',
                        'refunded' => 'Refunded',
                    ]),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('created_from'),
                        DatePicker::make('created_until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn(Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn(Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(
                        fn(Booking $record): bool =>
                        isset($record->meta_data['created_by_admin']) && $record->meta_data['created_by_admin'] === true
                    ),
                Action::make('invoice')
                    ->label('Invoice')
                    ->icon('heroicon-o-document-text')
                    ->color('success')
                    ->visible(fn(Booking $record): bool => $record->status === 'completed')
                    ->url(fn(Booking $record): string => route('admin.bookings.invoice', $record))
                    ->openUrlInNewTab(),
                ActionGroup::make([
                    Action::make('force_cancel')
                        ->label('Force Cancel')
                        ->icon('heroicon-o-x-mark')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Force Cancel Booking')
                        ->modalDescription('This will immediately cancel the booking regardless of current status.')
                        ->schema([
                            Textarea::make('reason')
                                ->label('Cancellation Reason')
                                ->required()
                                ->maxLength(500),
                        ])
                        ->action(function (Booking $record, array $data) {
                            return static::forceCancelBooking($record, $data);
                        })
                        ->visible(fn(Booking $record): bool => !in_array($record->status, ['completed', 'cancelled', 'expired'])),
                    Action::make('retry_matching')
                        ->label('Retry Matching')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Retry Driver Matching')
                        ->modalDescription('This will restart the driver matching process for this booking.')
                        ->action(function (Booking $record) {
                            return static::retryDriverMatching($record);
                        })
                        ->visible(fn(Booking $record): bool => in_array($record->status, ['searching', 'pending'])),
                    Action::make('assign_driver')
                        ->label('Assign Driver')
                        ->icon('heroicon-o-user-plus')
                        ->color('success')
                        ->schema([
                            Select::make('driver_id')
                                ->label('Select Driver')
                                ->options(function (Booking $record) {
                                    return User::drivers()
                                        ->active()
                                        ->where('is_online', true)
                                        ->whereHas('driverProfile', function ($q) use ($record) {
                                            $q->where('city_id', $record->city_id);
                                        })
                                        ->get()
                                        ->mapWithKeys(function ($driver) {
                                            return [$driver->id => $driver->name ?? 'Unknown'];
                                        });
                                })
                                ->searchable()
                                ->required(),
                            Textarea::make('assignment_reason')
                                ->label('Assignment Reason')
                                ->placeholder('Why is this driver being manually assigned?')
                                ->maxLength(255),
                        ])
                        ->action(function (Booking $record, array $data) {
                            return static::assignDriver($record, $data);
                        })
                        ->visible(fn(Booking $record): bool => in_array($record->status, ['searching', 'pending']) && !$record->driver_id),
                    Action::make('retry_payment')
                        ->label('Retry Payment')
                        ->icon('heroicon-o-credit-card')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Retry Payment Processing')
                        ->modalDescription('This will attempt to process the payment again.')
                        ->action(function (Booking $record) {
                            return static::retryPayment($record);
                        })
                        ->visible(fn(Booking $record): bool => $record->payment_status === 'failed'),
                    Action::make('force_complete')
                        ->label('Force Complete')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Force Complete Booking')
                        ->modalDescription('This will mark the booking as completed. Use only in emergency situations.')
                        ->schema([
                            TextInput::make('final_amount')
                                ->label('Final Amount')
                                ->numeric()
                                ->prefix('₹')
                                ->default(fn(Booking $record) => $record->estimated_fare)
                                ->required(),
                            Textarea::make('completion_reason')
                                ->label('Completion Reason')
                                ->required()
                                ->maxLength(500),
                        ])
                        ->action(function (Booking $record, array $data) {
                            return static::forceCompleteBooking($record, $data);
                        })
                        ->visible(fn(Booking $record): bool => in_array($record->status, ['started', 'arrived'])),
                ])->label('Actions'),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->modalHeading('Delete Selected Bookings')
                        ->modalDescription('Are you sure you want to delete the selected bookings? This action cannot be undone.')
                        ->modalSubmitActionLabel('Yes, delete them')
                        ->requiresConfirmation()
                        // Custom notification is sent below.
                        ->successNotification(null)
                        ->action(function ($records) {
                            // Block deletion for restricted users (ID 2)
                            $userId = auth()->id();
                            if ($userId === 2) {
                                Notification::make()
                                    ->title('Access Restricted')
                                    ->body('In demo mode you are not deleting data...')
                                    ->danger()
                                    ->send();
                                return;
                            }

                            // Default bulk delete behavior
                            foreach ($records as $record) {
                                $record->delete();
                            }

                            Notification::make()
                                ->title('Deleted')
                                ->body(count($records) . ' booking(s) have been deleted.')
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBookings::route('/'),
            'create' => CreateBooking::route('/create'),
            'view' => ViewBooking::route('/{record}'),
            'edit' => EditBooking::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'primary';
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['booking_code', 'pickup_address', 'dropoff_address'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['user', 'driver']);
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return "Booking #{$record->booking_code} - {$record->status}";
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        $details = [];

        if ($record->user) {
            $details['User'] = $record->user->name ?? 'N/A';
        }

        if ($record->driver) {
            $details['Driver'] = $record->driver->name ?? 'N/A';
        }

        if ($record->pickup_address) {
            $details['Pickup'] = \Str::limit($record->pickup_address, 40);
        }

        $details['Status'] = ucfirst($record->status);
        $details['Amount'] = '₹' . number_format($record->total_amount ?? 0, 2);

        return $details;
    }

    protected static function applyGlobalSearchAttributeConstraints(Builder $query, string $search): void
    {
        parent::applyGlobalSearchAttributeConstraints($query, $search);

        foreach (explode(' ', $search) as $searchWord) {
            $query->orWhere(function (Builder $query) use ($searchWord) {
                $query
                    ->whereHas('user', function (Builder $query) use ($searchWord) {
                        $query
                            ->where('name', 'like', "%{$searchWord}%")
                            ->orWhere('email', 'like', "%{$searchWord}%")
                            ->orWhere('phone', 'like', "%{$searchWord}%");
                    })
                    ->orWhereHas('driver', function (Builder $query) use ($searchWord) {
                        $query
                            ->where('name', 'like', "%{$searchWord}%")
                            ->orWhere('email', 'like', "%{$searchWord}%")
                            ->orWhere('phone', 'like', "%{$searchWord}%");
                    });
            });
        }
    }

    protected static function forceCancelBooking(Booking $record, array $data): void
    {
        try {
            DB::transaction(function () use ($record, $data) {
                $bookingService = app(BookingService::class);

                $bookingService->cancelBooking($record, [
                    'reason' => $data['reason'],
                    'cancelled_by' => 'admin',
                    'cancelled_by_id' => auth()->user()?->id,
                ]);

                // Backwards-compatible: some UIs don't send this key.
                $processRefund = (bool) ($data['process_refund'] ?? false);

                if ($processRefund && $record->payment_status === 'paid') {
                    $paymentService = app(PaymentGatewayService::class);

                    $paymentTransaction = $record
                        ->transactions()
                        ->where('type', 'payment')
                        ->where('status', 'completed')
                        ->latest()
                        ->first();

                    if ($paymentTransaction) {
                        $paymentService->refundPayment($paymentTransaction, $record->total_amount, $data['reason']);
                    }
                }

                // Update driver's current_booking_id to null if booking has a driver
                if ($record->driver_id) {
                    User::where('id', $record->driver_id)
                        ->where('current_booking_id', $record->id)
                        ->update(['current_booking_id' => null]);


                }

            });

            Notification::make()
                ->title('Booking Force Cancelled')
                ->body('The booking has been successfully cancelled.')
                ->success()
                ->send();
        } catch (Exception $e) {

            Notification::make()
                ->title('Cancellation Failed')
                ->body('Failed to cancel the booking: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    protected static function retryDriverMatching(Booking $record): void
    {
        try {
            $driverMatching = app(DriverMatchingService::class);

            $record->update(['status' => 'searching']);

            $driverMatching->startMatching($record);


            Notification::make()
                ->title('Driver Matching Restarted')
                ->body('The driver matching process has been restarted for this booking.')
                ->success()
                ->send();
        } catch (Exception $e) {

            Notification::make()
                ->title('Retry Failed')
                ->body('Failed to restart driver matching: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    protected static function assignDriver(Booking $record, array $data): void
    {
        try {
            DB::transaction(function () use ($record, $data) {
                $driver = User::findOrFail($data['driver_id']);

                $record->update([
                    'driver_id' => $driver->id,
                    'status' => 'accepted',
                    'accepted_at' => now(),
                ]);

                $driver->update(['is_online' => true]);  // Ensure they're online for the assignment


                event(new BookingStatusChanged($record, $record->status, 'Driver assigned via admin panel'));
            });

            Notification::make()
                ->title('Driver Assigned')
                ->body('The driver has been successfully assigned to this booking.')
                ->success()
                ->send();
        } catch (Exception $e) {

            Notification::make()
                ->title('Assignment Failed')
                ->body('Failed to assign driver: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    protected static function processRefund(Booking $record, array $data): void
    {
        try {
            DB::transaction(function () use ($record, $data) {
                $paymentService = app(PaymentGatewayService::class);

                $paymentTransaction = $record
                    ->transactions()
                    ->where('type', 'payment')
                    ->where('status', 'completed')
                    ->latest()
                    ->first();

                if (!$paymentTransaction) {
                    throw new Exception('No completed payment transaction found for this booking.');
                }

                $refundResult = $paymentService->refundPayment(
                    $paymentTransaction,
                    $data['refund_amount'],
                    $data['refund_reason']
                );

                if (!$refundResult['success']) {
                    throw new Exception($refundResult['message']);
                }

                $record->update(['payment_status' => 'refunded']);

            });

            Notification::make()
                ->title('Refund Processed')
                ->body('The refund has been successfully processed.')
                ->success()
                ->send();
        } catch (Exception $e) {

            Notification::make()
                ->title('Refund Failed')
                ->body('Failed to process refund: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    protected static function retryPayment(Booking $record): void
    {
        try {
            $paymentService = app(PaymentGatewayService::class);

            $result = $paymentService->processPayment($record, [
                'amount' => $record->total_amount,
                'payment_method' => $record->payment_method ?? 'wallet',
                'currency' => $record->currency ?? 'INR',
            ]);

            if ($result['success']) {
                Notification::make()
                    ->title('Payment Retry Successful')
                    ->body('The payment has been successfully processed.')
                    ->success()
                    ->send();
            } else {
                throw new Exception($result['message']);
            }

        } catch (Exception $e) {

            Notification::make()
                ->title('Payment Retry Failed')
                ->body('Failed to retry payment: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    protected static function forceCompleteBooking(Booking $record, array $data): void
    {
        try {
            DB::transaction(function () use ($record, $data) {
                $bookingService = app(BookingService::class);

                $record->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'total_amount' => $data['final_amount'],
                    'completion_notes' => $data['completion_reason'],
                ]);

                if ($record->payment_status !== 'paid') {
                    $record->update(['payment_status' => 'paid']);
                }


                event(new BookingStatusChanged($record, $record->status, 'Booking force completed via admin panel'));
            });

            Notification::make()
                ->title('Booking Force Completed')
                ->body('The booking has been marked as completed.')
                ->success()
                ->send();
        } catch (Exception $e) {

            Notification::make()
                ->title('Force Completion Failed')
                ->body('Failed to complete booking: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }
}
