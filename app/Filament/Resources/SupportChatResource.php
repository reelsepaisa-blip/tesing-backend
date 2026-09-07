<?php

namespace App\Filament\Resources;

use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Group;

use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteBulkAction;

use App\Filament\Resources\SupportChatResource\Pages;
use App\Models\SupportChat;
use App\Models\User;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class SupportChatResource extends Resource
{
    protected static ?string $model = SupportChat::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'Support Chat';

    protected static ?string $modelLabel = 'Support Conversation';

    protected static ?string $pluralModelLabel = 'Support Conversations';

    protected static ?int $navigationSort = 10;

    protected static string|\UnitEnum|null $navigationGroup = 'Support';

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
                Section::make('Support Chat Details')
                    ->schema([
                        Forms\Components\Select::make('user_id')
                            ->label('Customer')
                            ->options(User::where('role_id', 3)->pluck('name', 'id')->filter())
                            ->searchable()
                            ->preload()
                            ->required(),

                        Forms\Components\Select::make('admin_id')
                            ->label('Assigned Admin')
                            ->options(User::where('role_id', 1)->pluck('name', 'id')->filter())
                            ->searchable()
                            ->preload(),

                        Forms\Components\Select::make('sender_type')
                            ->label('Sender Type')
                            ->options([
                                'user' => 'Customer',
                                'admin' => 'Admin',
                            ])
                            ->required(),

                        Forms\Components\Textarea::make('message')
                            ->label('Message')
                            ->required()
                            ->rows(4)
                            ->columnSpanFull(),

                        Forms\Components\Select::make('message_type')
                            ->label('Message Type')
                            ->options([
                                'text' => 'Text',
                                'image' => 'Image',
                                'file' => 'File',
                                'system' => 'System',
                            ])
                            ->default('text')
                            ->required(),

                        Forms\Components\TextInput::make('subject')
                            ->label('Subject')
                            ->maxLength(200),

                        Forms\Components\Select::make('priority')
                            ->label('Priority')
                            ->options([
                                'low' => 'Low',
                                'medium' => 'Medium',
                                'high' => 'High',
                                'urgent' => 'Urgent',
                            ])
                            ->default('medium')
                            ->required(),

                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->options([
                                'open' => 'Open',
                                'closed' => 'Closed',
                                'pending' => 'Pending',
                            ])
                            ->default('open')
                            ->required(),

                        Forms\Components\Toggle::make('is_read')
                            ->label('Read')
                            ->default(false),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(
                SupportChat::query()
                    ->select('support_chats.*')
                    ->selectRaw('(SELECT COUNT(*) FROM support_chats sc WHERE sc.booking_id = support_chats.booking_id) as messages_count')
                    ->selectRaw('(SELECT COUNT(*) FROM support_chats sc WHERE sc.booking_id = support_chats.booking_id AND sc.is_read = 0 AND sc.sender_type = "user") as unread_count')
                    ->whereIn('id', function ($query) {
                        $query->selectRaw('MAX(id)')
                            ->from('support_chats')
                            ->groupBy('booking_id');
                    })
            )
            ->columns([
                Tables\Columns\TextColumn::make('booking.booking_code')
                    ->label('Booking Code')
                    ->searchable()
                    ->sortable()
                    ->placeholder('No booking')
                    ->weight('bold')
                    ->color('primary'),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('admin.name')
                    ->label('Assigned Admin')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Unassigned'),

                Tables\Columns\TextColumn::make('message')
                    ->label('Latest Message')
                    ->limit(50)
                    ->tooltip(fn($record) => $record->message)
                    ->extraAttributes(['class' => 'support-chat-message-cell']),

                Tables\Columns\TextColumn::make('messages_count')->badge()
                    ->label('Messages')
                    ->getStateUsing(fn($record) => $record->messages_count ?? 1)
                    ->color('primary'),

                Tables\Columns\TextColumn::make('unread_count')->badge()
                    ->label('Unread')
                    ->getStateUsing(fn($record) => $record->unread_count ?? 0)
                    ->color('danger')
                    ->visible(fn($record) => ($record->unread_count ?? 0) > 0),

                Tables\Columns\IconColumn::make('has_refund_request')
                    ->label('Refund')
                    ->boolean()
                    ->trueIcon('heroicon-o-currency-dollar')
                    ->falseIcon('heroicon-o-minus')
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->getStateUsing(
                        fn(SupportChat $record): bool =>
                        $record->metadata && isset($record->metadata['refund_request_id'])
                    )
                    ->tooltip(
                        fn(SupportChat $record): string => ($record->metadata && isset($record->metadata['refund_request_id']))
                        ? 'Has refund request'
                        : 'No refund request'
                    ),

                Tables\Columns\TextColumn::make('priority')->badge()
                    ->label('Priority')
                    ->colors([
                        'success' => 'low',
                        'primary' => 'medium',
                        'warning' => 'high',
                        'danger' => 'urgent',
                    ]),

                Tables\Columns\TextColumn::make('status')->badge()
                    ->label('Status')
                    ->colors([
                        'success' => 'open',
                        'warning' => 'pending',
                        'danger' => 'closed',
                    ]),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Last Message')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('sender_type')
                    ->label('Sender Type')
                    ->options([
                        'user' => 'Customer',
                        'admin' => 'Admin',
                    ]),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'open' => 'Open',
                        'closed' => 'Closed',
                        'pending' => 'Pending',
                    ]),

                SelectFilter::make('priority')
                    ->label('Priority')
                    ->options([
                        'low' => 'Low',
                        'medium' => 'Medium',
                        'high' => 'High',
                        'urgent' => 'Urgent',
                    ]),

                SelectFilter::make('admin_id')
                    ->label('Assigned Admin')
                    ->options(User::where('role_id', 1)->pluck('name', 'id')->filter())
                    ->searchable()
                    ->preload(),

                SelectFilter::make('booking_id')
                    ->label('Booking Code')
                    ->options(function () {
                        return \App\Models\Booking::whereHas('supportChats')
                            ->pluck('booking_code', 'id')
                            ->filter();
                    })
                    ->searchable()
                    ->preload(),

                Filter::make('unread')
                    ->label('Unread Messages')
                    ->query(fn(Builder $query): Builder => $query->where('is_read', false)),

                Filter::make('today')
                    ->label('Today')
                    ->query(fn(Builder $query): Builder => $query->whereDate('created_at', today())),
            ])
            ->actions([
                Action::make('chat')
                    ->label('Chat')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('primary')
                    ->url(fn(SupportChat $record): string => route('filament.admin.resources.support-chats.chat', $record)),

                ViewAction::make()
                    ->url(fn(SupportChat $record): string => route('filament.admin.resources.support-chats.view', $record)),

                EditAction::make(),

                Action::make('reply')
                    ->label('Quick Reply')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('success')
                    ->visible(fn(SupportChat $record): bool => $record->sender_type !== 'admin')
                    ->form([
                        Textarea::make('message')
                            ->label('Reply Message')
                            ->required()
                            ->rows(3),
                        Select::make('priority')
                            ->label('Priority')
                            ->options([
                                'low' => 'Low',
                                'medium' => 'Medium',
                                'high' => 'High',
                                'urgent' => 'Urgent',
                            ])
                            ->default('medium'),
                    ])
                    ->action(function (SupportChat $record, array $data): void {

                        $reply = SupportChat::create([
                            'user_id' => $record->user_id,
                            'booking_id' => $record->booking_id,
                            'admin_id' => Auth::id(),
                            'sender_type' => 'admin',
                            'message' => $data['message'],
                            'message_type' => 'text',
                            'priority' => $data['priority'],
                        ]);

                        $reply->load(['user', 'admin']);

                        event(new \App\Events\SupportChatMessage($reply));

                        Notification::make()
                            ->title('Reply sent successfully')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->action(function ($records) {
                            // Delete restrictions removed
                            // Default bulk delete behavior
                            $deletedCount = 0;
                            $permissionError = false;

                            foreach ($records as $record) {
                                try {
                                    $record->delete();
                                    $deletedCount++;
                                } catch (\Exception $e) {
                                    $errorMessage = $e->getMessage();
                                    if (str_contains($errorMessage, 'permission') || str_contains($errorMessage, 'restricted') || str_contains($errorMessage, 'demo')) {
                                        $permissionError = true;
                                    }
                                }
                            }

                            if ($deletedCount > 0) {
                                Notification::make()
                                    ->title("{$deletedCount} record(s) deleted")
                                    ->success()
                                    ->send();
                            }

                            if ($permissionError) {
                                Notification::make()
                                    ->title('Access Restricted')
                                    ->body('In demo mode you are not deleting data...')
                                    ->danger()
                                    ->send();
                            }
                        }),

                    BulkAction::make('mark_read')
                        ->label('Mark as Read')
                        ->icon('heroicon-o-check')
                        ->color('success')
                        ->action(function (Collection $records): void {
                            $records->each->markAsRead();

                            Notification::make()
                                ->title('Messages marked as read')
                                ->success()
                                ->send();
                        }),

                    BulkAction::make('assign_admin')
                        ->label('Assign to Admin')
                        ->icon('heroicon-o-user')
                        ->color('primary')
                        ->form([
                            Select::make('admin_id')
                                ->label('Admin')
                                ->options(User::where('role_id', 1)->pluck('name', 'id'))
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $records->each(function (SupportChat $record) use ($data) {
                                $record->update(['admin_id' => $data['admin_id']]);
                            });

                            Notification::make()
                                ->title('Messages assigned to admin')
                                ->success()
                                ->send();
                        }),

                    BulkAction::make('close_conversations')
                        ->label('Close Conversations')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->action(function (Collection $records): void {
                            $bookingIds = $records->pluck('booking_id')->unique()->filter();
                            SupportChat::whereIn('booking_id', $bookingIds)
                                ->update(['status' => 'closed']);

                            Notification::make()
                                ->title('Conversations closed')
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [

        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSupportChats::route('/'),
            'create' => Pages\CreateSupportChat::route('/create'),
            'view' => Pages\ViewSupportChat::route('/{record}'),
            'chat' => Pages\ChatSupportChat::route('/{record}/chat'),
            'edit' => Pages\EditSupportChat::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['user', 'admin', 'booking'])
            ->latest();
    }
}
