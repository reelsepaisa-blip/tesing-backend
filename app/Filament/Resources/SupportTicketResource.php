<?php

namespace App\Filament\Resources;

use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Group;

use Filament\Actions\EditAction;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteBulkAction;

use App\Events\SupportChatMessage;
use App\Filament\Resources\SupportTicketResource\Pages;
use App\Models\SupportChat;
use App\Models\SupportTicket;
use App\Models\User;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Forms;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class SupportTicketResource extends BaseResource
{
    protected static ?string $model = SupportTicket::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-ticket';

    protected static string|\UnitEnum|null $navigationGroup = 'Driver Management';

    protected static ?string $navigationLabel = 'Driver Report Tickets';

    protected static ?int $navigationSort = 5;

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Group::make()
                    ->schema([
                        Section::make('Ticket Information')
                            ->schema([
                                Forms\Components\TextInput::make('ticket_number')
                                    ->disabled(),
                                Forms\Components\Select::make('user_id')
                                    ->relationship('user', 'name', function ($query) {
                                        return $query->whereNotNull('name')->where('name', '!=', '');
                                    })
                                    ->getOptionLabelFromRecordUsing(fn($record) => $record->name ?: 'Unknown')
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->createOptionForm([
                                        Forms\Components\TextInput::make('name')
                                            ->required()
                                            ->maxLength(255),
                                        Forms\Components\TextInput::make('email')
                                            ->email()
                                            ->required()
                                            ->maxLength(255),
                                        Forms\Components\TextInput::make('phone')
                                            ->tel()
                                            ->required()
                                            ->maxLength(255),
                                    ]),
                                Forms\Components\Select::make('booking_id')
                                    ->relationship('booking', 'booking_code', function ($query) {
                                        return $query->whereNotNull('booking_code')->where('booking_code', '!=', '');
                                    })
                                    ->getOptionLabelFromRecordUsing(fn($record) => $record->booking_code ?: 'Unknown Booking')
                                    ->searchable()
                                    ->preload()
                                    ->nullable(),
                                Forms\Components\Select::make('category')
                                    ->options([
                                        SupportTicket::CATEGORY_RIDE_ISSUE => 'Ride Issue',
                                        SupportTicket::CATEGORY_PAYMENT_WALLET => 'Payment & Wallet',
                                        SupportTicket::CATEGORY_OFFER => 'Offer & Reward',
                                        SupportTicket::CATEGORY_TECHNICAL => 'App Related',
                                        SupportTicket::CATEGORY_OTHER => 'Other',
                                    ])
                                    ->required(),
                                Forms\Components\TextInput::make('subject')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpanFull(),
                            ]),
                        Section::make('Messages')
                            ->schema([
                                Forms\Components\Repeater::make('messages')
                                    ->relationship()
                                    ->schema([
                                        Forms\Components\Select::make('user_id')
                                            ->relationship('user', 'name')
                                            ->getOptionLabelFromRecordUsing(fn($record) => $record->name ?: 'Unknown')
                                            ->required(),
                                        Forms\Components\RichEditor::make('message')
                                            ->required()
                                            ->columnSpanFull(),
                                        Forms\Components\Toggle::make('is_internal')
                                            ->label('Internal Note'),
                                        Forms\Components\DateTimePicker::make('created_at')
                                            ->label('Timestamp')
                                            ->disabled(),
                                    ])
                                    ->disabled()
                                    ->reorderable(false)
                                    ->defaultItems(0)
                                    ->columnSpanFull(),
                                Forms\Components\RichEditor::make('new_message')
                                    ->label('Add Reply')
                                    ->columnSpanFull(),
                                Forms\Components\Toggle::make('is_internal_reply')
                                    ->label('Internal Note')
                                    ->default(false),
                                Forms\Components\FileUpload::make('attachments')
                                    ->multiple()
                                    ->maxFiles(5)
                                    ->maxSize(10240)  // 10MB
                                    ->acceptedFileTypes(['image/*', 'application/pdf'])
                                    ->directory('support-attachments')
                                    ->columnSpanFull(),
                            ])
                            ->collapsible(),
                    ])
                    ->columnSpan(['lg' => 2]),
                Group::make()
                    ->schema([
                        Section::make('Status & Assignment')
                            ->schema([
                                Forms\Components\Select::make('status')
                                    ->options([
                                        SupportTicket::STATUS_OPEN => 'Open',
                                        SupportTicket::STATUS_IN_PROGRESS => 'In Progress',
                                        SupportTicket::STATUS_WAITING => 'Waiting for Customer',
                                        SupportTicket::STATUS_RESOLVED => 'Resolved',
                                        SupportTicket::STATUS_CLOSED => 'Closed',
                                    ])
                                    ->required(),
                                Forms\Components\Select::make('priority')
                                    ->options([
                                        SupportTicket::PRIORITY_LOW => 'Low',
                                        SupportTicket::PRIORITY_MEDIUM => 'Medium',
                                        SupportTicket::PRIORITY_HIGH => 'High',
                                        SupportTicket::PRIORITY_URGENT => 'Urgent',
                                    ])
                                    ->required(),
                                Forms\Components\Select::make('assigned_to')
                                    ->label('Assign To')
                                    ->options(fn() => User::where('role_id', 1)->get()->mapWithKeys(function ($user) {
                                        return [$user->id => $user->name ?? 'Unknown User'];
                                    }))
                                    ->searchable()
                                    ->nullable(),
                            ]),
                        Section::make('Resolution')
                            ->schema([
                                Forms\Components\Textarea::make('resolution_note')
                                    ->label('Resolution Note')
                                    ->rows(3),
                                Forms\Components\DateTimePicker::make('resolved_at')
                                    ->label('Resolved At'),
                                // ->disabled(),
                                Forms\Components\DateTimePicker::make('closed_at')
                                    ->label('Closed At'),
                                // ->disabled(),
                            ])
                            ->collapsed(),
                        Section::make('Activity Timeline')
                            ->schema([
                                Forms\Components\ViewField::make('activities')
                                    ->view('filament.resources.support-ticket.activities'),
                            ])
                            ->hidden()
                            ->collapsed(),
                        Section::make('Driver Uploaded Images')
                            ->schema([
                                Forms\Components\ViewField::make('driver_attachments')
                                    ->view('filament.resources.support-ticket.driver-attachments'),
                            ])
                            ->collapsible()
                            ->collapsed(),
                    ])
                    ->columnSpan(['lg' => 1]),
            ])
            ->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('ticket_number')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('subject')
                    ->searchable()
                    ->sortable()
                    ->limit(50),
                Tables\Columns\TextColumn::make('category')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'open' => 'gray',
                        'in_progress' => 'info',
                        'waiting' => 'warning',
                        'resolved' => 'success',
                        'closed' => 'danger',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('priority')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'low' => 'gray',
                        'medium' => 'info',
                        'high' => 'warning',
                        'urgent' => 'danger',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('assignedTo.name')
                    ->label('Assigned To')
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_reply_at')
                    ->label('Last Reply')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'open' => 'Open',
                        'in_progress' => 'In Progress',
                        'waiting' => 'Waiting',
                        'resolved' => 'Resolved',
                        'closed' => 'Closed',
                    ]),
                Tables\Filters\SelectFilter::make('priority')
                    ->options([
                        'low' => 'Low',
                        'medium' => 'Medium',
                        'high' => 'High',
                        'urgent' => 'Urgent',
                    ]),
                Tables\Filters\SelectFilter::make('category')
                    ->options([
                        'booking' => 'Booking Related',
                        'payment' => 'Payment Related',
                        'driver' => 'Driver Related',
                        'account' => 'Account Related',
                        'technical' => 'Technical Issue',
                        'other' => 'Other',
                    ]),
                Tables\Filters\SelectFilter::make('assigned_to')
                    ->relationship('assignedTo', 'name')
                    ->getOptionLabelFromRecordUsing(fn($record) => $record->name ?: 'Unknown User'),
                Tables\Filters\Filter::make('needs_attention')
                    ->query(fn(Builder $query): Builder => $query->needsAttention()),
                Tables\Filters\Filter::make('unassigned')
                    ->query(fn(Builder $query): Builder => $query->whereNull('assigned_to')),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('created_from'),
                        Forms\Components\DatePicker::make('created_until'),
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
                EditAction::make(),
                Action::make('quick_reply')
                    ->form([
                        Forms\Components\RichEditor::make('message')
                            ->required(),
                        Forms\Components\Toggle::make('is_internal')
                            ->label('Internal Note')
                            ->default(false),
                    ])
                    ->action(function (SupportTicket $record, array $data): void {
                        $message = $record->messages()->create([
                            'user_id' => auth()->id() ?? 1,
                            'message' => $data['message'],
                            'is_internal' => $data['is_internal'],
                        ]);

                        if (!($data['is_internal'] ?? false)) {
                            // Map SupportTicket status to SupportChat status
                            $chatStatus = match ($record->status) {
                                SupportTicket::STATUS_OPEN, SupportTicket::STATUS_IN_PROGRESS => 'open',
                                SupportTicket::STATUS_WAITING => 'pending',
                                SupportTicket::STATUS_RESOLVED, SupportTicket::STATUS_CLOSED => 'closed',
                                default => 'open',
                            };

                            $supportChat = SupportChat::create([
                                'user_id' => $record->user_id,
                                'booking_id' => $record->booking_id,
                                'admin_id' => auth()->id() ?? null,
                                'sender_type' => 'admin',
                                'message' => strip_tags($data['message']),
                                'message_type' => 'text',
                                'metadata' => [
                                    'source' => 'admin_quick_reply',
                                    'ticket_id' => $record->id,
                                    'support_message_id' => $message->id,
                                ],
                                'is_read' => false,
                                'status' => $chatStatus,
                                'subject' => $record->subject,
                                'priority' => $record->priority,
                            ]);

                            event(new SupportChatMessage($supportChat));
                        }
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    BulkAction::make('assign')
                        ->form([
                            Forms\Components\Select::make('assigned_to')
                                ->label('Assign To')
                                ->options(function () {
                                    return User::where('role_id', 1)->get()->mapWithKeys(function ($user) {
                                        return [$user->id => $user->name ?? 'Unknown'];
                                    });
                                })
                                ->required(),
                        ])
                        ->action(function (array $data, \Illuminate\Database\Eloquent\Collection $records): void {
                            $assignee = User::find($data['assigned_to']);
                            foreach ($records as $record) {
                                $record->assign($assignee);
                            }
                        }),
                    BulkAction::make('close')
                        ->form([
                            Forms\Components\Textarea::make('note')
                                ->label('Closing Note')
                                ->required(),
                        ])
                        ->action(function (array $data, \Illuminate\Database\Eloquent\Collection $records): void {
                            foreach ($records as $record) {
                                $record->close($data['note']);
                            }
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSupportTickets::route('/'),
            'create' => Pages\CreateSupportTicket::route('/create'),
            'edit' => Pages\EditSupportTicket::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::whereNull('closed_at')->count();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return static::getModel()::whereNull('closed_at')->exists() ? 'warning' : null;
    }
}
