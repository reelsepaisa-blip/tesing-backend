<?php

namespace App\Filament\Resources;

use Filament\Schemas\Components\Group;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\IconEntry;
use Exception;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\DriverResource\Pages\ListDrivers;
use App\Filament\Resources\DriverResource\Pages\CreateDriver;
use App\Filament\Resources\DriverResource\Pages\ViewDriver;
use App\Filament\Resources\DriverResource\Pages\EditDriver;
use Filament\Forms\Components\Placeholder;
use App\Filament\Resources\DriverResource\Pages;
use App\Models\Document;
use App\Models\DocumentList;
use App\Models\RideType;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\FirebaseService;
use Filament\Infolists\Components\Actions\Action;
use Filament\Infolists\Components\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Forms;
use Filament\Infolists;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

class DriverResource extends BaseResource
{
    public static function getPermissionResourceName(): string
    {
        return 'drivers';
    }

    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static string|\UnitEnum|null $navigationGroup = 'Users Management';

    protected static ?string $navigationLabel = 'Drivers';

    protected static ?string $modelLabel = 'Driver';

    protected static ?string $pluralModelLabel = 'Drivers';

    protected static ?int $navigationSort = 4;

    public static function shouldRegisterNavigation(): bool
    {
        if (!Auth::check()) {
            return false;
        }

        if (Auth::id() === 1) {
            return true;
        }

        if (Auth::user()->role_id === 1) {
            return true;
        }

        return static::canViewAny();
    }

    public static function canViewAny(): bool
    {
        if (Auth::id() === 1) {
            return true;
        }

        if (Auth::check() && Auth::user()->role_id === 1) {
            return true;
        }

        return Auth::check();
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('role_id', 2)
            ->whereHas('vehicles')
            ->count();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Driver Information')
                    ->schema([
                        FileUpload::make('profile_photo')
                            ->label('Profile Photo')
                            ->image()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->rules(['mimes:jpg,jpeg,png,webp'])
                            ->maxSize(1024)
                            ->disk('public')
                            ->directory('')
                            ->visibility('public')
                            ->columnSpanFull(),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->formatStateUsing(function ($state, $record) {
                                if (auth()->check() && auth()->id() === 2 && $record && $state) {
                                    $length = strlen($state);
                                    if ($length <= 5) {
                                        return str_repeat('x', $length);
                                    }
                                    return substr($state, 0, $length - 5) . str_repeat('x', 5);
                                }
                                return $state;
                            })
                            ->dehydrateStateUsing(function ($state, $record) {
                                if (auth()->check() && auth()->id() === 2 && $record && preg_match('/x{5}$/', $state)) {
                                    return $record->name;
                                }
                                return $state;
                            }),
                        TextInput::make('phone')
                            ->label('Phone Number')
                            ->tel()
                            ->required()
                            ->maxLength(20)
                            ->rules(fn(string $operation): array => $operation === 'create'
                                ? [Rule::unique('users', 'phone')]
                                : [])
                            ->formatStateUsing(function ($state, $record) {
                                if (auth()->check() && auth()->id() === 2 && $record && $state) {
                                    $length = strlen($state);
                                    if ($length <= 5) {
                                        return str_repeat('x', $length);
                                    }
                                    return substr($state, 0, $length - 5) . str_repeat('x', 5);
                                }
                                return $state;
                            })
                            ->dehydrateStateUsing(function ($state, $record) {
                                if (auth()->check() && auth()->id() === 2 && $record && preg_match('/x{5}$/', $state)) {
                                    return $record->phone;
                                }
                                return $state;
                            }),
                        TextInput::make('email')
                            ->email()
                            ->nullable()
                            ->disabled()
                            ->maxLength(255)
                            ->rules(fn(string $operation): array => $operation === 'create'
                                ? [Rule::unique('users', 'email')]
                                : [])
                            ->formatStateUsing(function ($state, $record) {
                                if (auth()->check() && auth()->id() === 2 && $record && $state) {
                                    $length = strlen($state);
                                    if ($length <= 5) {
                                        return str_repeat('x', $length);
                                    }
                                    return substr($state, 0, $length - 5) . str_repeat('x', 5);
                                }
                                return $state;
                            })
                            ->dehydrateStateUsing(function ($state, $record) {
                                if (auth()->check() && auth()->id() === 2 && $record && preg_match('/x{5}$/', $state)) {
                                    return $record->email;
                                }
                                return $state;
                            }),
                        DatePicker::make('date_of_birth')
                            ->label('Date of Birth')
                            ->required()
                            ->maxDate(now()->subYears(18)),
                        Hidden::make('role')
                            ->default('driver'),
                    ])
                    ->columns(2),
                Section::make('Vehicle Information')
                    ->schema([
                        Select::make('ride_type_id')
                            ->label('Ride Type')
                            ->options(RideType::all()->mapWithKeys(function ($rideType) {
                                return [$rideType->id => $rideType->name ?? 'Unknown'];
                            }))
                            ->searchable()
                            ->preload()
                            ->required()
                            ->columnSpanFull(),
                        TextInput::make('registration_number')
                            ->label('Vehicle Register Number')
                            ->required()
                            ->maxLength(50)
                            ->columnSpan(1),
                        Select::make('vehicle_registration_status')
                            ->label('Vehicle Registration Status')
                            ->options([
                                'pending' => 'Pending',
                                'approved' => 'Approved',
                                'rejected' => 'Rejected',
                            ])
                            ->default('pending')
                            ->required()
                            ->live()
                            ->dehydrated(true)
                            ->visible(fn(Get $get): bool => (bool) $get('registration_number'))
                            ->visibleOn('edit')
                            ->columnSpan(1),
                        Textarea::make('vehicle_registration_rejection_reason')
                            ->label('Rejection Reason')
                            ->maxLength(255)
                            ->required(fn(Get $get) => $get('vehicle_registration_status') === 'rejected')
                            ->visible(fn(Get $get) => $get('vehicle_registration_status') === 'rejected')
                            ->columnSpanFull(),
                        TextInput::make('brand')
                            ->label('Vehicle Make')
                            ->required()
                            ->maxLength(100),
                        TextInput::make('model')
                            ->label('Vehicle Model')
                            ->required()
                            ->maxLength(100),
                        TextInput::make('year')
                            ->label('Vehicle Register Year')
                            ->numeric()
                            ->required()
                            ->minValue(1900)
                            ->maxValue(date('Y')),
                    ])
                    ->columns(2),
                Section::make('Identity Verification')
                    ->schema([
                        FileUpload::make('government_id_front')
                            ->label('Government ID Front Photo')
                            ->image()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->rules(['mimes:jpg,jpeg,png,webp'])
                            ->maxSize(1024)
                            ->disk('public')
                            ->directory('documents/government-id')
                            ->visibility('public')
                            ->required()
                            ->columnSpan(1)
                            ->visibleOn('create'),
                        FileUpload::make('government_id_back')
                            ->label('Government ID Back Photo')
                            ->image()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->rules(['mimes:jpg,jpeg,png,webp'])
                            ->maxSize(1024)
                            ->disk('public')
                            ->directory('documents/government-id')
                            ->visibility('public')
                            ->required()
                            ->columnSpan(1)
                            ->visibleOn('create'),
                        FileUpload::make('live_selfie')
                            ->label('Live Selfie (Optional)')
                            ->image()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->rules(['mimes:jpg,jpeg,png,webp'])
                            ->maxSize(1024)
                            ->disk('public')
                            ->directory('documents/live-selfie')
                            ->visibility('public')
                            ->required()
                            ->columnSpanFull()
                            ->visibleOn('create'),
                        Repeater::make('driver_document_requests')
                            ->label('Submitted Driver Documents')
                            ->schema(static::documentRequestSchema())
                            ->columns(4)
                            ->hidden(fn(Get $get) => empty($get('driver_document_requests')))
                            ->disableItemCreation()
                            ->disableItemDeletion()
                            ->collapsible()
                            ->dehydrated(true)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Vehicle Documentsss')
                    ->schema([
                        Repeater::make('vehicle_documents')
                            ->label('Vehicle Documents')
                            ->schema([
                                Select::make('document_type')
                                    ->label('Document Type')
                                    ->options(DocumentList::vehicle()->active()->ordered()->get()->mapWithKeys(function ($doc) {
                                        return [$doc->id => $doc->name ?? 'Unknown'];
                                    }))
                                    ->required()
                                    ->searchable(),
                                FileUpload::make('document_file')
                                    ->label('Document File')
                                    ->disk('public')
                                    ->directory('documents/vehicle')
                                    ->visibility('public')
                                    ->required()
                                    ->acceptedFileTypes(['image/*', 'application/pdf']),
                            ])
                            ->columns(2)
                            ->defaultItems(1)
                            ->addActionLabel('Add Vehicle Document')
                            ->collapsible()
                            ->visibleOn('create'),
                        Repeater::make('vehicle_document_requests')
                            ->label('Submitted Vehicle Documents')
                            ->schema(static::documentRequestSchema())
                            ->columns(4)
                            ->hidden(fn(Get $get) => empty($get('vehicle_document_requests')))
                            ->disableItemCreation()
                            ->disableItemDeletion()
                            ->collapsible()
                            ->dehydrated(true)
                            ->columnSpanFull(),
                    ]),
                Section::make('Referral & Status')
                    ->schema([
                        TextInput::make('referral_code')
                            ->label('Referral Code')
                            ->disabled()
                            ->dehydrated(false)
                            ->default(function () {
                                $code = strtoupper(substr('DRV', 0, 3) . rand(1000, 9999));
                                while (User::where('referral_code', $code)->exists()) {
                                    $code = strtoupper(substr('DRV', 0, 3) . rand(1000, 9999));
                                }
                                return $code;
                            })
                            ->helperText('This code will be auto-generated when the driver is created')
                            ->columnSpanFull(),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->hidden()
                            ->dehydrated(true)
                            ->default(true),
                        Toggle::make('is_available')
                            ->label('Available for Rides')
                            ->default(true)
                            ->live()
                            ->afterStateUpdated(function ($state, $set, $get, Get $getAll, $livewire) {
                                if ($state === true) {
                                    $record = $livewire->getRecord();

                                    if ($record && !$record->is_online) {
                                        $set('is_available', false);

                                        Notification::make()
                                            ->title('Driver is not online')
                                            ->body('Cannot set "Available for Rides" when the driver is offline. The driver must be online first.')
                                            ->danger()
                                            ->send();
                                    }
                                }
                            }),
                    ])
                    ->columns(3),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Driver Information')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                ImageEntry::make('profile_photo')
                                    ->label('Profile Photo')
                                    ->circular()
                                    ->defaultImageUrl(function ($record) {
                                        return 'https://ui-avatars.com/api/?name=' . urlencode($record->name ?? 'Driver') . '&color=f97316&background=1a1a1a';
                                    })
                                    ->getStateUsing(function ($record) {
                                        if (!$record->profile_photo) {
                                            return null;
                                        }
                                        return url('storage/' . $record->profile_photo);
                                    })
                                    ->columnSpan(1),
                                Grid::make(2)
                                    ->schema([
                                        TextEntry::make('name')
                                            ->label('Name')
                                            ->icon('heroicon-o-user')
                                            ->formatStateUsing(function ($state) {
                                                if (auth()->check() && auth()->id() === 2 && $state) {
                                                    $length = strlen($state);
                                                    if ($length <= 5) {
                                                        return str_repeat('x', $length);
                                                    }
                                                    return substr($state, 0, $length - 5) . str_repeat('x', 5);
                                                }
                                                return $state;
                                            }),
                                        TextEntry::make('phone')
                                            ->label('Phone Number')
                                            ->icon('heroicon-o-phone')
                                            ->formatStateUsing(function ($state) {
                                                if (auth()->check() && auth()->id() === 2 && $state) {
                                                    $length = strlen($state);
                                                    if ($length <= 5) {
                                                        return str_repeat('x', $length);
                                                    }
                                                    return substr($state, 0, $length - 5) . str_repeat('x', 5);
                                                }
                                                return $state;
                                            }),
                                        TextEntry::make('email')
                                            ->label('Email')
                                            ->icon('heroicon-o-envelope')
                                            ->formatStateUsing(function ($state) {
                                                if (auth()->check() && auth()->id() === 2) {
                                                    return $state ? str_repeat('x', min(strlen($state), 20)) : 'N/A';
                                                }
                                                return $state ?: 'N/A';
                                            }),
                                        TextEntry::make('date_of_birth')
                                            ->label('Date of Birth')
                                            ->date('d-m-Y')
                                            ->icon('heroicon-o-calendar'),
                                    ])
                                    ->columnSpan(2),
                            ]),
                    ]),
                Section::make('Vehicle Information')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextEntry::make('vehicles.rideType.name')
                                    ->label('Ride Type')
                                    ->state(function ($record) {
                                        $vehicle = $record->vehicles->first();
                                        return $vehicle?->rideType?->name ?? 'N/A';
                                    })
                                    ->icon('heroicon-o-truck'),
                                TextEntry::make('vehicles.registration_number')
                                    ->label('Vehicle Register Number')
                                    ->state(function ($record) {
                                        $vehicle = $record->vehicles->first();
                                        return $vehicle?->registration_number ?? 'N/A';
                                    })
                                    ->icon('heroicon-o-identification'),
                                TextEntry::make('registration_rejection_reason')
                                    ->label('Registration Rejection Reason')
                                    ->state(function ($record) {
                                        $meta = $record->driverProfile?->meta_data;
                                        if (is_array($meta) && isset($meta['vehicle_registration_rejection_reason'])) {
                                            return $meta['vehicle_registration_rejection_reason'];
                                        }

                                        $vehicle = $record->vehicles->first();
                                        return $vehicle?->rejection_reason ?? null;
                                    })
                                    ->visible(function ($record) {
                                        $meta = $record->driverProfile?->meta_data;
                                        if (is_array($meta) && isset($meta['vehicle_registration_status'])) {
                                            return $meta['vehicle_registration_status'] === 'rejected';
                                        }

                                        $vehicle = $record->vehicles->first();
                                        return $vehicle && $vehicle->status === 'rejected';
                                    })
                                    ->color('danger')
                                    ->columnSpanFull(),
                                TextEntry::make('vehicles.brand')
                                    ->label('Vehicle Make')
                                    ->state(function ($record) {
                                        $vehicle = $record->vehicles->first();
                                        return $vehicle?->brand ?? 'N/A';
                                    })
                                    ->icon('heroicon-o-cog-6-tooth'),
                                TextEntry::make('vehicles.model')
                                    ->label('Vehicle Model')
                                    ->state(function ($record) {
                                        $vehicle = $record->vehicles->first();
                                        return $vehicle?->model ?? 'N/A';
                                    }),
                                TextEntry::make('vehicles.year')
                                    ->label('Vehicle Year')
                                    ->state(function ($record) {
                                        $vehicle = $record->vehicles->first();
                                        return $vehicle?->year ?? 'N/A';
                                    }),
                            ]),
                    ])
                    ->columns(1),
                Section::make('Status & Verification')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                IconEntry::make('is_active')
                                    ->label('Active')
                                    ->boolean(),
                                IconEntry::make('is_verified')
                                    ->label('Verified')
                                    ->boolean(),
                                IconEntry::make('is_available')
                                    ->label('Available for Rides')
                                    ->boolean(),
                            ]),
                    ])
                    ->collapsible(),
                Section::make('Driver Performance')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('overall_rating')
                                    ->label('Overall Rating')
                                    ->state(function ($record) {
                                        $completedBookings = $record
                                            ->bookingsAsDriver()
                                            ->where('status', 'completed')
                                            ->whereNotNull('user_rating')
                                            ->where('user_rating', '>', 0);

                                        $ratingCount = $completedBookings->count();

                                        if ($ratingCount > 0) {
                                            $avgRating = $completedBookings->avg('user_rating');
                                            return number_format($avgRating, 2) . ' / 5.0';
                                        }

                                        return 'No Rating Yet';
                                    })
                                    ->badge()
                                    ->color(function ($state) {
                                        if ($state === 'No Rating Yet') {
                                            return 'gray';
                                        }
                                        $rating = (float) str_replace(' / 5.0', '', $state);
                                        if ($rating >= 4.5) {
                                            return 'success';
                                        } elseif ($rating >= 4.0) {
                                            return 'warning';
                                        } else {
                                            return 'danger';
                                        }
                                    })
                                    ->icon('heroicon-o-star'),
                                TextEntry::make('overall_earnings')
                                    ->label('Overall Earnings')
                                    ->state(function ($record) {
                                        if ($record->wallet) {
                                            return number_format((float) $record->wallet->balance, 2);
                                        }

                                        return number_format(0, 2);
                                    })
                                    ->money('INR')
                                    ->icon('heroicon-o-currency-dollar')
                                    ->color('success'),
                                TextEntry::make('complaint_ratio')
                                    ->label('Complaint Ratio')
                                    ->state(function ($record) {
                                        $completedTrips = $record->bookingsAsDriver()->where('status', 'completed')->count();

                                        $complaintsCount = 0;

                                        $bookingIds = $record->bookingsAsDriver()->pluck('id');

                                        try {
                                            $complaintsCount = DB::table('complaints')
                                                ->whereIn('booking_id', $bookingIds)
                                                ->whereIn('type', ['driver_behavior', 'safety', 'overcharge', 'route', 'cleanliness'])
                                                ->count();
                                        } catch (Exception $e) {
                                            try {
                                                $complaintsCount = DB::table('support_tickets')
                                                    ->whereIn('booking_id', $bookingIds)
                                                    ->whereNotNull('booking_id')
                                                    ->count();
                                            } catch (Exception $e2) {
                                                $complaintsCount = 0;
                                            }
                                        }

                                        if ($completedTrips == 0) {
                                            return '0.00%';
                                        }

                                        $ratio = ($complaintsCount / $completedTrips) * 100;
                                        return number_format($ratio, 2) . '%';
                                    })
                                    ->badge()
                                    ->color(function ($state) {
                                        $percentage = (float) str_replace('%', '', $state);
                                        if ($percentage <= 2.0) {
                                            return 'success';
                                        } elseif ($percentage <= 5.0) {
                                            return 'warning';
                                        } else {
                                            return 'danger';
                                        }
                                    })
                                    ->icon('heroicon-o-exclamation-triangle'),
                            ]),
                    ])
                    ->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                ImageColumn::make('profile_photo')
                    ->label('Profile Photo')
                    ->getStateUsing(function ($record) {
                        if (!$record->profile_photo) {
                            return null;
                        }
                        return url('storage/' . $record->profile_photo);
                    })
                    ->defaultImageUrl(function ($record) {
                        return 'https://ui-avatars.com/api/?name=' . urlencode($record->name ?? 'Driver') . '&color=f97316&background=1a1a1a';
                    })
                    ->circular(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(function ($state) {
                        if (auth()->check() && auth()->id() === 2 && $state) {
                            $length = strlen($state);
                            if ($length <= 5) {
                                return str_repeat('x', $length);
                            }
                            return substr($state, 0, $length - 5) . str_repeat('x', 5);
                        }
                        return $state;
                    }),
                TextColumn::make('phone')
                    ->label('Phone Number')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(function ($state) {
                        if (auth()->check() && auth()->id() === 2 && $state) {
                            $length = strlen($state);
                            if ($length <= 5) {
                                return str_repeat('x', $length);
                            }
                            return substr($state, 0, $length - 5) . str_repeat('x', 5);
                        }
                        return $state;
                    }),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(function ($state) {
                        if (auth()->check() && auth()->id() === 2) {
                            return str_repeat('x', min(strlen($state ?? ''), 20));
                        }
                        return $state;
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('date_of_birth')
                    ->label('Date of Birth')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('vehicles.registration_number')
                    ->label('Vehicle Reg. No.')
                    ->searchable(),
                TextColumn::make('vehicles.brand')
                    ->label('Vehicle Make')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('vehicles.model')
                    ->label('Vehicle Model')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('vehicles.year')
                    ->label('Vehicle Year')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('vehicles.rideType.name')
                    ->label('Ride Type')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('referral_code')
                    ->label('Referral Code')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')
                    ->label('Active/Block')
                    ->boolean(),
                IconColumn::make('is_verified')
                    ->label('Verified')
                    ->boolean(),
                IconColumn::make('is_available')
                    ->label('Available')
                    ->boolean(),
                TextColumn::make('documents_status')
                    ->label('Documents')
                    ->state(function ($record) {
                        if (!$record->relationLoaded('documents')) {
                            $record->load('documents');
                        }
                        $documents = $record->documents ?? collect();
                        $driverDocs = $documents->where('documentable_type', 'App\Models\User');

                        if (!$record->relationLoaded('vehicles')) {
                            $record->load('vehicles.documents');
                        }
                        $vehicleDocs = collect();
                        foreach ($record->vehicles as $vehicle) {
                            if (!$vehicle->relationLoaded('documents')) {
                                $vehicle->load('documents');
                            }
                            $vehicleDocuments = $vehicle->documents ?? collect();
                            $vehicleDocs = $vehicleDocs->merge(
                                $vehicleDocuments->where('documentable_type', 'App\Models\Vehicle')
                            );
                        }

                        $allDocs = $driverDocs->merge($vehicleDocs);

                        if ($allDocs->isEmpty()) {
                            return 'No Documents';
                        }

                        $total = $allDocs->count();
                        $approved = $allDocs->where('status', 'approved')->count();
                        $pending = $allDocs->where('status', 'pending')->count();
                        $rejected = $allDocs->where('status', 'rejected')->count();

                        return "{$approved}/{$pending}/{$rejected} ({$total})";
                    })
                    ->badge()
                    ->color(function ($record) {
                        if (!$record->relationLoaded('documents')) {
                            $record->load('documents');
                        }
                        $documents = $record->documents ?? collect();
                        $driverDocs = $documents->where('documentable_type', 'App\Models\User');

                        if (!$record->relationLoaded('vehicles')) {
                            $record->load('vehicles.documents');
                        }
                        $vehicleDocs = collect();
                        foreach ($record->vehicles as $vehicle) {
                            if (!$vehicle->relationLoaded('documents')) {
                                $vehicle->load('documents');
                            }
                            $vehicleDocuments = $vehicle->documents ?? collect();
                            $vehicleDocs = $vehicleDocs->merge(
                                $vehicleDocuments->where('documentable_type', 'App\Models\Vehicle')
                            );
                        }

                        $allDocs = $driverDocs->merge($vehicleDocs);

                        if ($allDocs->isEmpty()) {
                            return 'gray';
                        }

                        $total = $allDocs->count();
                        $approved = $allDocs->where('status', 'approved')->count();
                        $rejected = $allDocs->where('status', 'rejected')->count();
                        $pending = $allDocs->where('status', 'pending')->count();

                        if ($total === $approved && $total > 0) {
                            return 'success';
                        } elseif ($rejected > 0) {
                            return 'danger';
                        } elseif ($pending > 0) {
                            return 'warning';
                        }

                        return 'gray';
                    })
                    ->tooltip(function ($record) {
                        if (!$record->relationLoaded('documents')) {
                            $record->load('documents');
                        }
                        $documents = $record->documents ?? collect();
                        $driverDocs = $documents->where('documentable_type', 'App\Models\User');

                        if (!$record->relationLoaded('vehicles')) {
                            $record->load('vehicles.documents');
                        }
                        $vehicleDocs = collect();
                        foreach ($record->vehicles as $vehicle) {
                            if (!$vehicle->relationLoaded('documents')) {
                                $vehicle->load('documents');
                            }
                            $vehicleDocuments = $vehicle->documents ?? collect();
                            $vehicleDocs = $vehicleDocs->merge(
                                $vehicleDocuments->where('documentable_type', 'App\Models\Vehicle')
                            );
                        }

                        $allDocs = $driverDocs->merge($vehicleDocs);

                        if ($allDocs->isEmpty()) {
                            return 'No documents uploaded';
                        }

                        $total = $allDocs->count();
                        $approved = $allDocs->where('status', 'approved')->count();
                        $pending = $allDocs->where('status', 'pending')->count();
                        $rejected = $allDocs->where('status', 'rejected')->count();

                        return "Total: {$total} | Approved: {$approved} | Pending: {$pending} | Rejected: {$rejected}";
                    })
                    ->sortable(false)  // Complex sorting with multiple relationships - disabled for now
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active')
                    ->queries(
                        true: fn(Builder $query) => $query->where('status', 'active'),
                        false: fn(Builder $query) => $query->where('status', '!=', 'active'),
                        blank: fn(Builder $query) => $query,
                    ),
                TernaryFilter::make('is_verified')
                    ->label('Verified'),
                TernaryFilter::make('is_available')
                    ->label('Available')
                    ->queries(
                        true: fn(Builder $query) => $query
                            ->where('status', 'active')
                            ->where('is_online', true)
                            ->where('is_verified', true)
                            ->whereHas('driverProfile')
                            ->whereHas('vehicles', fn($q) => $q->where('status', 'active')),
                        false: fn(Builder $query) => $query
                            ->where(function ($q) {
                                $q->where('status', '!=', 'active')
                                    ->orWhere('is_online', false)
                                    ->orWhere('is_verified', false)
                                    ->orWhereDoesntHave('driverProfile')
                                    ->orWhereDoesntHave('vehicles', fn($vq) => $vq->where('status', 'active'));
                            }),
                        blank: fn(Builder $query) => $query,
                    ),
                SelectFilter::make('vehicles.year')
                    ->label('Vehicle Year')
                    ->options(function () {
                        $years = [];
                        for ($year = date('Y'); $year >= 1990; $year--) {
                            $years[$year] = $year;
                        }
                        return $years;
                    }),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
                \Filament\Actions\Action::make('activate')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(fn(User $record) => $record->update(['status' => 'active']))
                    ->visible(fn(User $record) => $record->status !== 'active'),
                \Filament\Actions\Action::make('block')
                    ->icon('heroicon-o-shield-exclamation')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn(User $record) => $record->update(['status' => 'blocked']))
                    ->visible(fn(User $record) => $record->status !== 'blocked'),
                DeleteAction::make()
                    ->modalHeading('Delete Driver')
                    ->modalDescription('Are you sure you want to delete this driver? This will also remove the driver from Firebase and cannot be undone.')
                    ->modalSubmitActionLabel('Yes, delete driver')
                    ->requiresConfirmation()
                    // We send a custom notification in the action, so disable Filament's default "Deleted" toast.
                    ->successNotification(null)
                    ->action(function (User $record) {
                        // Delete restrictions removed
                        try {
                            // Delete Firebase user before deleting from database
                            try {
                                $firebaseService = app(FirebaseService::class);
                                $firebaseService->deleteUser($record->firebase_uid, $record->email, $record->phone);
                            } catch (Exception $e) {
                                // Continue with database deletion even if Firebase deletion fails
                            }

                            // Temporarily disable foreign key checks to allow deletion
                            // Bookings will remain but with orphaned driver_id references
                            DB::statement('SET FOREIGN_KEY_CHECKS=0;');

                            // Hard delete the driver
                            $record->forceDelete();

                            // Re-enable foreign key checks
                            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

                            Notification::make()
                                ->title('Driver deleted')
                                ->body('Driver has been deleted.')
                                ->success()
                                ->send();
                        } catch (Exception $e) {
                            // Re-enable foreign key checks in case of error
                            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

                            // Check if it's a permission restriction
                            $errorMessage = $e->getMessage();
                            if (str_contains($errorMessage, 'permission') || str_contains($errorMessage, 'restricted') || str_contains($errorMessage, 'demo')) {
                                Notification::make()
                                    ->title('Access Restricted')
                                    ->body('In demo mode you are not deleting data...')
                                    ->danger()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('Deletion failed')
                                    ->body('An error occurred while deleting the driver: ' . $errorMessage)
                                    ->danger()
                                    ->send();
                            }
                        }
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('activate')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(fn($records) => $records->each->update(['status' => 'active'])),
                    BulkAction::make('block')
                        ->icon('heroicon-o-shield-exclamation')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(fn($records) => $records->each->update(['status' => 'blocked'])),
                    DeleteBulkAction::make()
                        ->modalHeading('Delete Selected Drivers')
                        ->modalDescription('Are you sure you want to delete the selected drivers? This will also remove them from Firebase and cannot be undone.')
                        ->modalSubmitActionLabel('Yes, delete them')
                        ->requiresConfirmation()
                        // Custom notifications are sent below.
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

                            $deletedCount = 0;
                            $failedCount = 0;

                            try {
                                // Temporarily disable foreign key checks to allow deletion
                                DB::statement('SET FOREIGN_KEY_CHECKS=0;');

                                $permissionError = false;
                                foreach ($records as $record) {
                                    try {
                                        // Delete Firebase user before deleting from database
                                        try {
                                            $firebaseService = app(FirebaseService::class);
                                            $firebaseService->deleteUser($record->firebase_uid, $record->email, $record->phone);
                                        } catch (Exception $e) {
                                            // Continue with database deletion even if Firebase deletion fails
                                        }

                                        // Hard delete the driver (bookings remain with orphaned driver_id)
                                        $record->forceDelete();
                                        $deletedCount++;
                                    } catch (Exception $e) {
                                        $failedCount++;
                                        // Check if it's a permission restriction
                                        $errorMessage = $e->getMessage();
                                        if (str_contains($errorMessage, 'permission') || str_contains($errorMessage, 'restricted') || str_contains($errorMessage, 'demo')) {
                                            $permissionError = true;
                                        }
                                    }
                                }

                                // Re-enable foreign key checks
                                DB::statement('SET FOREIGN_KEY_CHECKS=1;');

                                if ($deletedCount > 0) {
                                    Notification::make()
                                        ->title("{$deletedCount} driver(s) deleted")
                                        ->body('Drivers have been permanently deleted. Associated bookings remain in the system.')
                                        ->success()
                                        ->send();
                                }

                                if ($failedCount > 0) {
                                    if ($permissionError) {
                                        Notification::make()
                                            ->title('Access Restricted')
                                            ->body('In demo mode you are not deleting data...')
                                            ->danger()
                                            ->send();
                                    } else {
                                        Notification::make()
                                            ->title("{$failedCount} driver(s) failed to delete")
                                            ->body('An error occurred while deleting these drivers.')
                                            ->danger()
                                            ->send();
                                    }
                                }
                            } catch (Exception $e) {
                                // Re-enable foreign key checks in case of error
                                DB::statement('SET FOREIGN_KEY_CHECKS=1;');

                                // Check if it's a permission restriction
                                $errorMessage = $e->getMessage();
                                if (str_contains($errorMessage, 'permission') || str_contains($errorMessage, 'restricted') || str_contains($errorMessage, 'demo')) {
                                    Notification::make()
                                        ->title('Access Restricted')
                                        ->body('In demo mode you are not deleting data...')
                                        ->danger()
                                        ->send();
                                } else {
                                    Notification::make()
                                        ->title('Bulk deletion failed')
                                        ->body('An error occurred: ' . $errorMessage)
                                        ->danger()
                                        ->send();
                                }
                            }
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
            'index' => ListDrivers::route('/'),
            'create' => CreateDriver::route('/create'),
            'view' => ViewDriver::route('/{record}'),
            'edit' => EditDriver::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('role_id', 2)
            ->whereHas('vehicles')
            ->with(['vehicles.rideType', 'vehicles.documents', 'driverProfile', 'documents']);
    }

    protected static function documentRequestSchema(): array
    {
        return [
            Hidden::make('id'),
            Hidden::make('front_url')->dehydrated(false),
            Hidden::make('back_url')->dehydrated(false),
            Hidden::make('submitted_at')->dehydrated(false),
            TextInput::make('display_name')
                ->label('Document')
                ->disabled()
                ->dehydrated(false)
                ->columnSpan(2),
            Placeholder::make('front_file')
                ->label('Image')
                ->content(fn(Get $get) => static::renderDocumentPreview($get('front_url')))
                ->columnSpan(1)
                ->dehydrated(false),
            Select::make('status')
                ->label('Status')
                ->options([
                    'pending' => 'Pending',
                    'approved' => 'Approved',
                    'rejected' => 'Rejected',
                ])
                ->live()
                ->required()
                ->columnSpan(1),
            Placeholder::make('submitted_at_display')
                ->label('Submitted On')
                ->content(fn(Get $get) => $get('submitted_at') ?? '—')
                ->columnSpan(1)
                ->dehydrated(false),
            Textarea::make('rejection_reason')
                ->label('Rejection Reason')
                ->maxLength(255)
                ->required(fn(Get $get) => $get('status') === 'rejected')
                ->visible(fn(Get $get) => $get('status') === 'rejected')
                ->columnSpanFull(),
        ];
    }

    protected static function renderDocumentPreview(?string $url): HtmlString
    {
        if (!$url) {
            return new HtmlString('<span class="text-gray-500">Not uploaded</span>');
        }

        $safeUrl = e($url);
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg'];

        if (in_array($extension, $imageExtensions, true)) {
            $imageTag = sprintf(
                '<a href="%s" target="_blank" class="inline-flex justify-center">
                    <img src="%s" alt="Document preview" loading="lazy"
                        class="max-w-[180px] h-32 object-contain rounded border border-gray-200 shadow-sm" />
                </a>',
                $safeUrl,
                $safeUrl
            );

            return new HtmlString($imageTag);
        }

        return new HtmlString(
            '<a href="' . $safeUrl . '" target="_blank" class="text-primary-600 hover:underline">'
            . 'View'
            . '</a>'
        );
    }
}
