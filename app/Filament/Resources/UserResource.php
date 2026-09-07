<?php

namespace App\Filament\Resources;

use Filament\Schemas\Components\Group;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
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
use Filament\Forms\Components\Hidden;
use Filament\Schemas\Components\Utilities\Set;

class UserResource extends BaseResource
{
    protected static ?string $model = User::class;

    /** Permission name for role/permission system (Admin Users, not riders). */
    public static function getPermissionResourceName(): string
    {
        return 'admin_users';
    }

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string|\UnitEnum|null $navigationGroup = 'Users Management';

    protected static ?string $navigationLabel = 'Admin Users';


    protected static ?int $navigationSort = 4;


    public static function mapRoleNameToRoleId(string $roleName): int
    {
        return match (strtolower($roleName)) {
            'admin' => 1,                       // Admin role
            'superadmin', 'super_admin' => 1,   // Superadmin role (same role_id as admin, but different Spatie role)
            'driver' => 2,                      // Driver role
            'user' => 3,                        // Regular user role
            'support' => 4,                     // Support role


            default => 1                        // Default to admin for unknown roles
        };
    }

    /**
     * Resolve the role name to display in forms (must exist in Role dropdown options).
     */
    public static function getResolvedRoleNameForRecord(?Model $record): string
    {
        if (! $record) {
            return 'admin';
        }
        $roleName = $record->roles->first()?->name ?? $record->role ?? null;
        $allowedRoleNames = \Spatie\Permission\Models\Role::where('guard_name', 'web')
            ->whereNotIn('name', ['driver', 'user'])
            ->pluck('name')
            ->toArray();
        if ($roleName && in_array($roleName, $allowedRoleNames, true)) {
            return $roleName;
        }
        $roleId = (int) ($record->role_id ?? 1);
        $fallback = \Spatie\Permission\Models\Role::where('guard_name', 'web')
            ->whereNotIn('name', ['driver', 'user'])
            ->get()
            ->first(fn ($r) => static::mapRoleNameToRoleId($r->name) === $roleId);
        return $fallback?->name ?? ($allowedRoleNames[0] ?? 'admin');
    }

    public static function getNavigationBadge(): ?string
    {

        return static::getModel()::whereNotIn('role_id', [2, 3])->count();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make()
                    ->schema([
                        Grid::make()
                            ->schema([
                                Forms\Components\FileUpload::make('profile_photo')
                                    ->label('Avatar')
                                    ->image()
                                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                    ->rules(['mimes:jpg,jpeg,png,webp'])
                                    ->maxSize(1024) // 1MB
                                    ->helperText('Upload a profile photo (JPEG, PNG, or WebP, max 1MB)')
                                    ->columnSpanFull(),

                                Forms\Components\TextInput::make('name')
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

                                Forms\Components\TextInput::make('email')
                                    ->email()
                                    ->required()
                                    ->maxLength(255)
                                    ->unique(
                                        table: 'users',
                                        column: 'email',
                                        ignorable: fn($record) => $record
                                    )
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

                                Forms\Components\Select::make('role')
                                    ->required()
                                    ->label('Role')
                                    ->options(function () {
                                        return \Spatie\Permission\Models\Role::where('guard_name', 'web')
                                            ->whereNotIn('name', ['driver', 'user'])
                                            ->pluck('name', 'name')
                                            ->mapWithKeys(function ($name) {
                                                return [$name => ucfirst(str_replace('_', ' ', $name))];
                                            });
                                    })
                                    ->default(fn ($record) => $record ? static::getResolvedRoleNameForRecord($record) : 'admin')
                                    ->live()
                                    ->afterStateUpdated(function (mixed $state, Set $set, $livewire) {

                                        if ($state) {

                                            $set('role_name', $state);

                                            if (property_exists($livewire, 'selectedRole')) {
                                                $livewire->selectedRole = $state;
                                            }

                                            $role = \Spatie\Permission\Models\Role::where('guard_name', 'web')
                                                ->where('name', $state)
                                                ->first();

                                            if ($role) {



                                                $usersTableRoleId = static::mapRoleNameToRoleId($role->name);
                                                $set('role_id', $usersTableRoleId);
                                            } else {

                                                $set('role_id', 1);
                                            }
                                        }
                                    })
                                    ->dehydrated(true),

                                Hidden::make('role_name')
                                    ->default('admin')
                                    ->dehydrated(),

                                Hidden::make('role_id')
                                    ->default(1) // Default to admin role_id (1) for users table
                                    ->dehydrated(),

                                Forms\Components\TextInput::make('password')
                                    ->password()
                                    ->dehydrateStateUsing(fn($state) => filled($state) ? Hash::make($state) : null)
                                    ->dehydrated(fn($state) => filled($state))
                                    ->required(fn(string $context): bool => $context === 'create')
                                    ->helperText(fn(string $context): string => $context === 'edit' ? 'Leave blank to keep current password' : ''),
                            ]),

                        Section::make('Status & Verification')
                            ->schema([
                                Forms\Components\Select::make('status')
                                    ->options([
                                        'active' => 'Active',
                                        'inactive' => 'Inactive',
                                        'blocked' => 'Blocked',
                                        'under_review' => 'Under Review',
                                    ])
                                    ->default('active')
                                    ->disabled(function ($livewire): bool {
                                        if (! auth()->check()) {
                                            return false;
                                        }
                                        $record = method_exists($livewire, 'getRecord') ? $livewire->getRecord() : null;
                                        return $record && (int) auth()->id() === (int) $record->getKey();
                                    })
                                    ->helperText(function ($livewire): ?string {
                                        if (! auth()->check()) {
                                            return null;
                                        }
                                        $record = method_exists($livewire, 'getRecord') ? $livewire->getRecord() : null;
                                        return $record && (int) auth()->id() === (int) $record->getKey()
                                            ? 'You cannot change your own status (would lock you out of the panel).'
                                            : null;
                                    }),
                            ])
                            ->columns(1),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\ImageColumn::make('profile_photo')
                    ->label('Avatar')
                    ->circular()
                    ->defaultImageUrl(function ($record) {
                        return 'https://ui-avatars.com/api/?name=' . urlencode($record->name ?? 'User') . '&color=f97316&background=1a1a1a';
                    }),
                Tables\Columns\TextColumn::make('name')
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
                Tables\Columns\TextColumn::make('email')
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
                Tables\Columns\TextColumn::make('phone')
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
                Tables\Columns\TextColumn::make('role')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'admin' => 'danger',
                        'driver' => 'warning',
                        'user' => 'success',
                        'support' => 'info',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'active' => 'success',
                        'inactive' => 'gray',
                        'blocked' => 'danger',
                        'under_review' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->options(function () {
                        return \Spatie\Permission\Models\Role::where('guard_name', 'web')
                            ->pluck('name', 'name')
                            ->mapWithKeys(function ($name) {
                                return [$name => ucfirst(str_replace('_', ' ', $name))];
                            });
                    })
                    ->query(function (Builder $query, array $data): Builder {

                        $selectedRoles = $data['value'] ?? $data['values'] ?? null;

                        if (!empty($selectedRoles)) {

                            $roles = is_array($selectedRoles) ? $selectedRoles : [$selectedRoles];

                            $roleIds = collect($roles)->map(function ($roleName) {

                                return static::mapRoleNameToRoleId($roleName);
                            })->filter()->unique()->values()->toArray();

                            if (!empty($roleIds)) {
                                return $query->whereIn('role_id', $roleIds);
                            }
                        }
                        return $query;
                    }),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                        'blocked' => 'Blocked',
                        'under_review' => 'Under Review',
                    ]),
                Tables\Filters\TernaryFilter::make('is_online')
                    ->label('Online'),
            ])
            ->actions([
                ViewAction::make()
                    ->mutateRecordDataUsing(function (array $data, User $record): array {
                        $roleName = static::getResolvedRoleNameForRecord($record);
                        $data['role'] = $roleName;
                        $data['role_name'] = $roleName;
                        $data['role_id'] = $record->role_id;
                        return $data;
                    })
                    ->modalWidth('2xl'),
                Action::make('edit')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil')
                    ->color('warning')
                    ->url(function (User $record): string {
                        if ($record->role_id === 2) {
                            return '/admin/drivers/' . $record->id . '/edit';
                        }
                        return static::getUrl('edit', ['record' => $record]);
                    }),
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
                    ->visible(fn(User $record) => $record->status !== 'blocked' && $record->id !== auth()->id() && $record->id !== 1),

                DeleteAction::make()
                    ->requiresConfirmation()
                    ->action(function (User $record) {
                        // Delete restrictions removed
                        $record->delete();

                        Notification::make()
                            ->title('User deleted')
                            ->body('User has been deleted successfully.')
                            ->success()
                            ->send();
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
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            // Delete restrictions removed
                            foreach ($records as $record) {
                                $record->delete();
                            }

                            Notification::make()
                                ->title('Users deleted')
                                ->body(count($records) . ' user(s) have been deleted successfully.')
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
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $query->whereNotIn('role_id', [2, 3])->with('roles');

        return $query;
    }

    protected static bool $isGloballySearchable = true;

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email', 'phone'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {

        $role = ucfirst($record->role ?? 'User');
        return "{$record->name} ({$role})";
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {

        $details = [];

        if ($record->email) {
            if (auth()->check() && auth()->id() === 2) {
                $length = strlen($record->email);
                $details['Email'] = $length <= 5 ? str_repeat('x', $length) : substr($record->email, 0, $length - 5) . str_repeat('x', 5);
            } else {
                $details['Email'] = $record->email;
            }
        }

        if ($record->phone) {
            if (auth()->check() && auth()->id() === 2) {
                $length = strlen($record->phone);
                $details['Phone'] = $length <= 5 ? str_repeat('x', $length) : substr($record->phone, 0, $length - 5) . str_repeat('x', 5);
            } else {
                $details['Phone'] = $record->phone;
            }
        }

        $details['Status'] = ucfirst($record->status ?? 'active');

        return $details;
    }
}
