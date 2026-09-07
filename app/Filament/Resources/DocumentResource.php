<?php

namespace App\Filament\Resources;

use Filament\Schemas\Components\Group;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use App\Models\User;
use App\Models\Vehicle;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\DateTimePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Resources\DocumentResource\Pages\EditDocument;
use App\Filament\Resources\DocumentResource\Pages;
use App\Filament\Resources\DocumentResource\RelationManagers;
use App\Models\Document;
use App\Models\DocumentList;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-document-text';

    protected static string | \UnitEnum | null $navigationGroup = 'Driver Management';

    protected static ?string $navigationLabel = 'Document Requests';

    protected static ?int $navigationSort = 100;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canCreate(): bool
    {
        $userId = auth()->id();

        // User ID 1 can create
        if ($userId === 1) {
            return true;
        }

        // User ID 2 cannot create documents (special restriction for documents)
        if ($userId === 2) {
            return false;
        }

        return true; // Other users follow default permissions
    }

    public static function canEdit(Model $record): bool
    {
        $userId = auth()->id();

        // User ID 1 can edit
        if ($userId === 1) {
            return true;
        }

        // User ID 2 cannot edit documents (special restriction for documents)
        if ($userId === 2) {
            return false;
        }

        return true; // Other users follow default permissions
    }

    public static function canDelete(Model $record): bool
    {
        $userId = auth()->id();

        // User ID 1 can delete
        if ($userId === 1) {
            return true;
        }

        // User ID 2 cannot delete (already restricted globally)
        if ($userId === 2) {
            return false;
        }

        return true; // Other users follow default permissions
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Document Information')
                    ->schema([
                        Select::make('documentable_type')
                            ->label('Document Owner Type')
                            ->options([
                                'App\Models\User' => 'Driver',
                                'App\Models\Vehicle' => 'Vehicle',

                            ])
                            ->disabled()
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(fn(callable $set) => $set('documentable_id', null)),

                        Select::make('documentable_id')
                            ->label('Document Owner')
                            ->required()
                            ->disabled()
                            ->searchable()
                            ->getSearchResultsUsing(function (string $search, callable $get) {
                                $type = $get('documentable_type');
                                if (!$type) return [];

                                if ($type === 'App\Models\User') {
                                    return User::withTrashed()
                                        ->where('role_id', 2)
                                        ->whereNotNull('name')
                                        ->where('name', '!=', '')
                                        ->where('name', 'like', "%{$search}%")
                                        ->limit(50)
                                        ->get()
                                        ->mapWithKeys(function ($user) {
                                            $name = $user->name;
                                            if ($user->trashed()) {
                                                $name .= ' (Deleted)';
                                            }
                                            return [$user->id => $name];
                                        })
                                        ->filter() // Remove any null values
                                        ->toArray();
                                } elseif ($type === 'App\Models\Vehicle') {
                                    return Vehicle::withTrashed()
                                        ->where('brand', 'like', "%{$search}%")
                                        ->orWhere('model', 'like', "%{$search}%")
                                        ->orWhereHas('driver', function ($query) use ($search) {
                                            $query->withTrashed()->where('name', 'like', "%{$search}%");
                                        })
                                        ->with(['driver' => function ($query) {
                                            $query->withTrashed();
                                        }])
                                        ->limit(50)
                                        ->get()
                                        ->mapWithKeys(function ($vehicle) {
                                            $driverName = 'No Driver';
                                            if ($vehicle->driver) {
                                                $driverName = $vehicle->driver->name ?: 'No Driver';
                                                if ($vehicle->driver->trashed()) {
                                                    $driverName .= ' (Deleted)';
                                                }
                                            }
                                            $brand = $vehicle->brand ?: 'Unknown Brand';
                                            $model = $vehicle->model ?: 'Unknown Model';
                                            $plate = $vehicle->registration_number ?: 'No Plate';
                                            $vehicleStatus = $vehicle->trashed() ? ' (Deleted)' : '';
                                            return [$vehicle->id => "{$driverName} - {$brand} {$model} ({$plate}){$vehicleStatus}"];
                                        })
                                        ->filter() // Remove any null values
                                        ->toArray();
                                }

                                return [];
                            })
                            ->getOptionLabelUsing(function ($value, callable $get): string {
                                $type = $get('documentable_type');
                                if (!$type || !$value) return '';

                                if ($type === 'App\Models\User') {
                                    $user = User::withTrashed()->find($value);
                                    if ($user && $user->name) {
                                        return $user->trashed() ? $user->name . ' (Deleted)' : $user->name;
                                    }
                                    return 'Unknown User';
                                } elseif ($type === 'App\Models\Vehicle') {
                                    $vehicle = Vehicle::withTrashed()->with('driver')->find($value);
                                    if ($vehicle) {
                                        $driverName = 'No Driver';
                                        if ($vehicle->driver) {
                                            $driverName = $vehicle->driver->name ?: 'No Driver';
                                            if ($vehicle->driver->trashed()) {
                                                $driverName .= ' (Deleted)';
                                            }
                                        } elseif ($vehicle->driver_id) {
                                            $driver = User::withTrashed()->find($vehicle->driver_id);
                                            if ($driver) {
                                                $driverName = $driver->name ?: 'No Driver';
                                                if ($driver->trashed()) {
                                                    $driverName .= ' (Deleted)';
                                                }
                                            }
                                        }
                                        $brand = $vehicle->brand ?: 'Unknown Brand';
                                        $model = $vehicle->model ?: 'Unknown Model';
                                        $plate = $vehicle->registration_number ?: 'No Plate';
                                        $vehicleStatus = $vehicle->trashed() ? ' (Deleted)' : '';
                                        return "{$driverName} - {$brand} {$model} ({$plate}){$vehicleStatus}";
                                    }
                                    return 'Unknown Vehicle';
                                }

                                return 'Unknown';
                            }),

                        Select::make('type')
                            ->label('Document Type')
                            ->disabled()
                            ->options(function () {
                                return DocumentList::where('is_active', true)
                                    ->orderBy('sort_order')
                                    ->orderBy('name')
                                    ->pluck('name', 'name')
                                    ->toArray();
                            })
                            ->searchable(),



                    ])
                    ->columns(2),

                Section::make('Document Files')
                    ->schema([
                        FileUpload::make('file_front')
                            ->label('Front Side File')
                            ->disk('public')
                            ->directory('documents')
                            ->disabled()
                            ->acceptedFileTypes(['image/*', 'application/pdf'])
                            ->hidden(fn(callable $get) => !$get('file_front'))
                            ->maxSize(5120) // 5MB
                            ->openable(),

                        FileUpload::make('file_back')
                            ->label('Back Side File')
                            ->disk('public')
                            ->disabled()
                            ->directory('documents')
                            ->acceptedFileTypes(['image/*', 'application/pdf'])
                            ->hidden(fn(callable $get) => !$get('file_back'))
                            ->openable()
                            ->maxSize(5120), // 5MB


                    ])
                    ->columns(2),

                Section::make('Status & Verification')
                    ->schema([
                        Select::make('status')
                            ->label('Status')
                            ->options([
                                'pending' => 'Pending',
                                'approved' => 'Approved',
                                'rejected' => 'Rejected',
                            ])
                            ->required()
                            ->default('pending')
                            ->live()
                            ->afterStateUpdated(function ($state, callable $set) {
                                if ($state === 'approved' || $state === 'pending' || $state === 'rejected') {
                                    $set('verified_by', Auth::id());
                                    $set('verified_at', now()->format('Y-m-d H:i:s'));
                                } else {
                                    $set('verified_by', null);
                                    $set('verified_at', null);
                                }
                            }),

                        Textarea::make('rejection_reason')
                            ->label('Rejection Reason')
                            ->maxLength(255)
                            ->visible(fn(callable $get) => $get('status') === 'rejected'),

                        Select::make('verified_by')
                            ->label('Verified By')
                            ->options(function () {
                                return User::withTrashed()
                                    ->whereNotNull('name')
                                    ->get()
                                    ->mapWithKeys(function ($user) {
                                        $name = $user->name;
                                        if ($user->trashed()) {
                                            $name .= ' (Deleted)';
                                        }
                                        return [$user->id => $name];
                                    })
                                    ->toArray();
                            })
                            ->disabled()
                            ->dehydrated()
                            ->getOptionLabelUsing(function ($value): string {
                                if (!$value) return 'Not Set';
                                $user = User::withTrashed()->find($value);
                                if ($user) {
                                    $name = $user->name ?? 'Unknown';
                                    if ($user->trashed()) {
                                        $name .= ' (Deleted)';
                                    }
                                    return $name;
                                }
                                return 'Unknown';
                            }),

                        DateTimePicker::make('verified_at')
                            ->label('Verified At')
                            ->disabled()
                            ->dehydrated()
                            ->reactive(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {

                return $query

                    ->where(function ($q) {
                        $q->where('documentable_type', '!=', 'App\Models\User')
                            ->orWhere(function ($userQuery) {
                                $userQuery->where('documentable_type', 'App\Models\User')
                                    ->whereExists(function ($existsQuery) {
                                        $existsQuery->select(DB::raw(1))
                                            ->from('users')
                                            ->whereColumn('users.id', 'documents.documentable_id')
                                            ->whereNull('users.deleted_at');
                                    });
                            });
                    })

                    ->where(function ($q) {
                        $q->where('documentable_type', '!=', 'App\Models\Vehicle')
                            ->orWhere(function ($vehicleQuery) {
                                $vehicleQuery->where('documentable_type', 'App\Models\Vehicle')
                                    ->whereExists(function ($existsQuery) {
                                        $existsQuery->select(DB::raw(1))
                                            ->from('vehicles')
                                            ->join('users', 'users.id', '=', 'vehicles.driver_id')
                                            ->whereColumn('vehicles.id', 'documents.documentable_id')
                                            ->whereNull('users.deleted_at');
                                    });
                            });
                    })
                    ->with([
                        'verifiedBy' => function ($query) {
                            $query->withTrashed();
                        },
                    ]);
            })
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('documentable_type')
                    ->label('Document For')
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'App\Models\User' => 'Driver',
                        'App\Models\Vehicle' => 'Vehicle',
                        default => $state,
                    })
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'App\Models\User' => 'success',
                        'App\Models\Vehicle' => 'info',
                        default => 'gray',
                    }),

                TextColumn::make('documentable.name')
                    ->label('Driver Name')
                    ->getStateUsing(function ($record) {
                        if ($record->documentable_type === 'App\Models\User') {

                            if (!$record->documentable && $record->documentable_id) {
                                $user = User::withTrashed()->find($record->documentable_id);
                                if ($user) {
                                    $record->setRelation('documentable', $user);
                                }
                            }
                            $name = $record->documentable?->name ?? 'Deleted Driver';

                            if ($record->documentable && $record->documentable->trashed()) {
                                return $name . ' (Deleted)';
                            }
                            return $name;
                        } elseif ($record->documentable_type === 'App\Models\Vehicle') {

                            if (!$record->documentable && $record->documentable_id) {
                                $vehicle = Vehicle::withTrashed()->find($record->documentable_id);
                                if ($vehicle) {
                                    $record->setRelation('documentable', $vehicle);
                                }
                            }
                            $vehicle = $record->documentable;
                            if ($vehicle) {

                                if (!$vehicle->relationLoaded('driver') && $vehicle->driver_id) {
                                    $driver = User::withTrashed()->find($vehicle->driver_id);
                                    if ($driver) {
                                        $vehicle->setRelation('driver', $driver);
                                    }
                                }
                                if ($vehicle->driver) {
                                    $name = $vehicle->driver->name ?? 'Unknown Driver';

                                    if ($vehicle->driver->trashed()) {
                                        return $name . ' (Deleted)';
                                    }
                                    return $name;
                                }
                            }
                            return 'Unknown Driver';
                        }
                        return 'Unknown';
                    })
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function ($query) use ($search) {

                            $query->where(function ($q) use ($search) {
                                $q->where('documentable_type', 'App\Models\User')
                                    ->whereHasMorph('documentable', ['App\Models\User'], function ($q) use ($search) {
                                        $q->withTrashed()
                                            ->where('name', 'like', "%{$search}%")
                                            ->orWhere('phone', 'like', "%{$search}%");
                                    });
                            })

                                ->orWhere(function ($q) use ($search) {
                                    $q->where('documentable_type', 'App\Models\Vehicle')
                                        ->whereHasMorph('documentable', ['App\Models\Vehicle'], function ($q) use ($search) {
                                            $q->withTrashed()
                                                ->whereHas('driver', function ($driverQuery) use ($search) {
                                                    $driverQuery->withTrashed()
                                                        ->where('name', 'like', "%{$search}%")
                                                        ->orWhere('phone', 'like', "%{$search}%");
                                                });
                                        });
                                });
                        });
                    })
                    ->sortable(false),

                TextColumn::make('type')
                    ->label('Document Type')
                    ->formatStateUsing(function (string $state): string {

                        $documentList = DocumentList::where('name', $state)
                            ->orWhereRaw('LOWER(REPLACE(name, " ", "_")) = ?', [strtolower(str_replace(' ', '_', $state))])
                            ->first();

                        if ($documentList) {
                            return $documentList->name;
                        }

                        return match ($state) {
                            'driving_license' => 'Driving License',
                            'identity_proof' => 'Identity Proof',
                            'registration_certificate' => 'Registration Certificate',
                            'insurance' => 'Insurance',
                            'permit' => 'Permit',
                            'fitness_certificate' => 'Fitness Certificate',
                            'vehicle_photo' => 'Vehicle Photo',
                            default => $state,
                        };
                    })
                    ->badge()
                    ->color('primary'),





                TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        'pending' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('rejection_reason')
                    ->label('Rejection Reason')
                    ->limit(50)
                    ->visible(fn($record) => $record && $record->status === 'rejected')
                    ->color('danger'),






                TextColumn::make('verified_at')
                    ->label('Verified At')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('verifiedBy.name')
                    ->label('Verified By')
                    ->getStateUsing(function ($record) {
                        if (!$record->verifiedBy && $record->verified_by) {
                            $user = User::withTrashed()->find($record->verified_by);
                            if ($user) {
                                $record->setRelation('verifiedBy', $user);
                            }
                        }
                        $name = $record->verifiedBy?->name;
                        if ($record->verifiedBy && $record->verifiedBy->trashed()) {
                            $name .= ' (Deleted)';
                        }
                        return $name;
                    })
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Created At')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('documentable_type')
                    ->label('Owner Type')
                    ->options([
                        'App\Models\User' => 'Driver',
                        'App\Models\Vehicle' => 'Vehicle',
                    ]),

                SelectFilter::make('type')
                    ->label('Document Type')
                    ->options(function () {
                        return DocumentList::where('is_active', true)
                            ->orderBy('sort_order')
                            ->orderBy('name')
                            ->pluck('name', 'name')
                            ->toArray();
                    }),

                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ]),

                TrashedFilter::make(),
            ])
            ->actions([
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
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
            'index' => ListDocuments::route('/'),

            'edit' => EditDocument::route('/{record}/edit'),
        ];
    }
}
