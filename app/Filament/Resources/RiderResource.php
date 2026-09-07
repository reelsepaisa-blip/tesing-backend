<?php

namespace App\Filament\Resources;

use Filament\Schemas\Components\Group;

use App\Filament\Resources\RiderResource\Pages;
use App\Models\User;
use App\Models\Booking;
use App\Services\FirebaseService;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Filament\Notifications\Notification;
use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;

class RiderResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static string|\UnitEnum|null $navigationGroup = 'Users Management';

    protected static ?string $navigationLabel = 'Customers';

    protected static ?int $navigationSort = 4;

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('role_id', 3)->where('is_register', 1)->count();
    }

    public static function canDelete(Model $record): bool
    {
        $userId = auth()->id();

        // User ID 1 can delete
        if ($userId === 1) {
            return true;
        }

        // User ID 2 cannot delete
        if ($userId === 2) {
            return false;
        }

        return true; // Other users follow default permissions
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Rider Information')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Forms\Components\FileUpload::make('profile_photo')
                                    ->label('Profile Photo')
                                    ->image()
                                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                    ->rules(['mimes:jpg,jpeg,png,webp'])
                                    ->maxSize(1024)
                                    ->helperText('Upload a profile photo (JPEG, PNG, or WebP, max 1MB)')
                                    ->columnSpanFull()
                                    ->directory('profile-photos'),

                                Forms\Components\TextInput::make('name')
                                    ->required()
                                    ->maxLength(255)
                                    ->label('Full Name')
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

                                Forms\Components\TextInput::make('email')
                                    ->email()

                                    ->maxLength(255)
                                    ->rules([
                                        function ($record) {
                                            return function (string $attribute, $value, \Closure $fail) use ($record) {

                                                if ($record && $record->email === $value) {
                                                    return;
                                                }

                                                // Skip validation if masked value and user is ID 2
                                                if (auth()->check() && auth()->id() === 2 && preg_match('/x{5}$/', $value)) {
                                                    return;
                                                }

                                                $roleId = $record ? $record->role_id : 3;

                                                $exists = \App\Models\User::where('email', $value)
                                                    ->where('role_id', $roleId)
                                                    ->when($record, fn($query) => $query->where('id', '!=', $record->id))
                                                    ->exists();

                                                if ($exists) {
                                                    $fail('The email address has already been taken.');
                                                }
                                            };
                                        },
                                    ])
                                    ->label('Email Address')
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

                                Forms\Components\TextInput::make('phone')
                                    ->tel()
                                    ->required()
                                    ->maxLength(20)
                                    ->unique(
                                        table: 'users',
                                        column: 'phone',
                                        ignorable: fn($record) => $record
                                    )
                                    ->label('Phone Number')
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

                                Forms\Components\TextInput::make('country_code')
                                    ->label('Country Code')
                                    ->maxLength(5)
                                    ->default('+1')
                                    ->placeholder('+1'),

                                Forms\Components\Select::make('gender')
                                    ->options([
                                        'male' => 'Male',
                                        'female' => 'Female',
                                        'other' => 'Other',
                                    ])
                                    ->label('Gender'),

                                Forms\Components\TextInput::make('password')
                                    ->password()
                                    ->dehydrateStateUsing(fn($state) => Hash::make($state))
                                    ->dehydrated(fn($state) => filled($state))
                                    ->required(fn(string $context): bool => $context === 'create')
                                    ->label('Password')
                                    ->helperText('Leave blank to keep current password'),

                                Forms\Components\TextInput::make('address')
                                    ->maxLength(500)
                                    ->label('Address')
                                    ->hidden()
                                    ->columnSpanFull(),
                            ]),
                    ]),

                Section::make('Status & Verification')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Forms\Components\Select::make('status')
                                    ->options([
                                        'active' => 'Active',
                                        'inactive' => 'Inactive',
                                        'blocked' => 'Blocked',
                                        'under_review' => 'Under Review',
                                    ])
                                    ->default('active')
                                    ->required(),

                                Forms\Components\Toggle::make('is_online')
                                    ->label('Is Online')
                                    ->default(false),

                                Forms\Components\Toggle::make('is_verified')
                                    ->label('Is Verified')
                                    ->default(false),
                            ]),
                    ]),

                Section::make('Referral Information')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('referral_code')
                                    ->label('Referral Code')
                                    ->maxLength(20)
                                    ->disabled()
                                    ->helperText('Auto-generated on creation'),

                                Forms\Components\Select::make('referred_by')
                                    ->label('Referred By')
                                    ->options(function () {
                                        return User::where('role_id', 3)
                                            ->where('is_register', 1)
                                            ->get()
                                            ->mapWithKeys(function ($user) {
                                                return [$user->id => $user->name . ' (ID: ' . $user->id . ')'];
                                            });
                                    })
                                    ->searchable()
                                    ->preload()
                                    ->helperText('Select the user who referred this rider'),
                            ]),
                    ])
                    ->collapsible(),

                Section::make('Additional Information')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Forms\Components\DatePicker::make('date_of_birth')
                                    ->label('Date of Birth')
                                    ->maxDate(now()->subYears(13)),

                                Forms\Components\Textarea::make('device_token')
                                    ->label('Device Token')
                                    ->rows(3)
                                    ->hidden()
                                    ->helperText('For push notifications')
                                    ->columnSpanFull(),
                            ]),
                    ])
                    ->collapsible()
                    ->collapsed(),

                Forms\Components\Hidden::make('role_id')
                    ->default(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\ImageColumn::make('profile_photo')
                    ->label('Photo')
                    ->circular()
                    ->defaultImageUrl(function ($record) {
                        return 'https://ui-avatars.com/api/?name=' . urlencode($record->name) . '&color=f97316&background=1a1a1a';
                    }),

                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
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

                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable()
                    ->icon('heroicon-m-envelope')
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

                Tables\Columns\TextColumn::make('phone')
                    ->searchable()
                    ->sortable()
                    ->icon('heroicon-m-phone')
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

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'active' => 'success',
                        'inactive' => 'gray',
                        'blocked' => 'danger',
                        'under_review' => 'warning',
                        default => 'gray',
                    })
                    ->icon(fn(string $state): string => match ($state) {
                        'active' => 'heroicon-o-check-circle',
                        'inactive' => 'heroicon-o-x-circle',
                        'blocked' => 'heroicon-o-shield-exclamation',
                        'under_review' => 'heroicon-o-clock',
                        default => 'heroicon-o-question-mark-circle',
                    }),






                Tables\Columns\IconColumn::make('is_verified')
                    ->boolean()
                    ->label('Verified')
                    ->trueIcon('heroicon-o-check-badge')
                    ->falseIcon('heroicon-o-x-circle'),

                Tables\Columns\TextColumn::make('referral_code')
                    ->label('Referral')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->copyable()
                    ->copyMessage('Referral code copied'),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->label('Joined')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->label('Last Updated')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                        'blocked' => 'Blocked',
                        'under_review' => 'Under Review',
                    ])
                    ->label('Status'),

                Tables\Filters\TernaryFilter::make('is_online')
                    ->label('Online Status')
                    ->placeholder('All riders')
                    ->trueLabel('Online riders only')
                    ->falseLabel('Offline riders only'),

                Tables\Filters\TernaryFilter::make('is_verified')
                    ->label('Verification Status')
                    ->placeholder('All riders')
                    ->trueLabel('Verified riders only')
                    ->falseLabel('Unverified riders only'),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('created_from')
                            ->label('Joined from'),
                        Forms\Components\DatePicker::make('created_until')
                            ->label('Joined until'),
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
                EditAction::make(),
                Action::make('activate')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(fn(User $record) => $record->update(['status' => 'active']))
                    ->visible(fn(User $record) => $record->status !== 'active'),
                Action::make('block')
                    ->icon('heroicon-o-shield-exclamation')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn(User $record) => $record->update(['status' => 'blocked']))
                    ->visible(fn(User $record) => $record->status !== 'blocked'),
                DeleteAction::make()
                    ->modalHeading('Delete Customer')
                    ->modalDescription('Are you sure you want to delete this customer? This will also remove the customer from Firebase and cannot be undone.')
                    ->modalSubmitActionLabel('Yes, delete customer')
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
                            } catch (\Exception $e) {
                                // Continue with database deletion even if Firebase deletion fails
                            }

                            // Temporarily disable foreign key checks to allow deletion
                            // Bookings will remain but with orphaned user_id references
                            DB::statement('SET FOREIGN_KEY_CHECKS=0;');

                            // Hard delete the user
                            $record->forceDelete();

                            // Re-enable foreign key checks
                            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

                            Notification::make()
                                ->title('Customer deleted')
                                ->body('Customer has been deleted.')
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
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
                                    ->body('An error occurred while deleting the customer: ' . $errorMessage)
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
                        ->modalHeading('Delete Selected Customers')
                        ->modalDescription('Are you sure you want to delete the selected customers? This will also remove them from Firebase and cannot be undone.')
                        ->modalSubmitActionLabel('Yes, delete them')
                        ->requiresConfirmation()
                        // Custom notifications are sent below.
                        ->successNotification(null)
                        ->action(function ($records) {
                            // Delete restrictions removed
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
                                        } catch (\Exception $e) {
                                            // Continue with database deletion even if Firebase deletion fails
                                        }

                                        // Hard delete the user (bookings remain with orphaned user_id)
                                        $record->forceDelete();
                                        $deletedCount++;
                                    } catch (\Exception $e) {
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
                                        ->title("{$deletedCount} customer(s) deleted")
                                        ->body('Customers have been permanently deleted. Associated bookings remain in the system.')
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
                                            ->title("{$failedCount} customer(s) failed to delete")
                                            ->body('An error occurred while deleting these customers.')
                                            ->danger()
                                            ->send();
                                    }
                                }
                            } catch (\Exception $e) {
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
            'index' => Pages\ListRiders::route('/'),
            'create' => Pages\CreateRider::route('/create'),
            'edit' => Pages\EditRider::route('/{record}/edit'),
            'view' => Pages\ViewRider::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('role_id', 3)
            ->where('is_register', 1);
    }

    public static function getModelLabel(): string
    {
        return 'Customer';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Customers';
    }
}
