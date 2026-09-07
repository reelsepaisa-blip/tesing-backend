<?php

namespace App\Filament\Resources\SupportChatResource\Pages;

use App\Filament\Resources\SupportChatResource;
use App\Models\RefundRequest;
use App\Models\SupportChat;
use App\Models\User;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Infolists;
use Filament\Schemas\Schema;
use App\Filament\Resources\Pages\ViewRecord;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ViewSupportChat extends ViewRecord
{
    protected static string $resource = SupportChatResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),

            Actions\Action::make('reply')
                ->label('Send Reply')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('primary')
                ->visible(fn(SupportChat $record): bool => $record->sender_type !== 'admin')
                ->form([
                    Section::make('Send Reply')
                        ->schema([
                            Textarea::make('message')
                                ->label('Reply Message')
                                ->required()
                                ->rows(4)
                                ->placeholder('Type your reply here...'),

                            Select::make('message_type')
                                ->label('Message Type')
                                ->options([
                                    'text' => 'Text',
                                    'image' => 'Image',
                                    'file' => 'File',
                                ])
                                ->default('text')
                                ->required(),

                            FileUpload::make('attachment')
                                ->label('Attachment')
                                ->acceptedFileTypes(['image/*', 'application/pdf', 'text/*'])
                                ->maxSize(5120) // 5MB
                                ->visible(fn(Get $get) => in_array($get('message_type'), ['image', 'file'])),

                            Select::make('priority')
                                ->label('Priority')
                                ->options([
                                    'low' => 'Low',
                                    'medium' => 'Medium',
                                    'high' => 'High',
                                    'urgent' => 'Urgent',
                                ])
                                ->default('medium')
                                ->required(),
                        ])
                        ->columns(2),
                ])
                ->action(function (SupportChat $record, array $data): void {

                    $reply = SupportChat::create([
                        'user_id' => $record->user_id,
                        'booking_id' => $record->booking_id, // Include booking_id
                        'admin_id' => Auth::id(),
                        'sender_type' => 'admin',
                        'message' => $data['message'],
                        'message_type' => $data['message_type'],
                        'priority' => $data['priority'],
                        'metadata' => isset($data['attachment']) && $data['attachment'] ? [
                            'attachment_path' => $data['attachment'],
                            'attachment_url' => asset('storage/' . $data['attachment']),
                        ] : null,
                    ]);

                    $reply->load(['user', 'admin']);

                    event(new \App\Events\SupportChatMessage($reply));

                    if ($record->status === 'closed') {
                        SupportChat::where('user_id', $record->user_id)
                            ->update(['status' => 'open']);
                    }

                    Notification::make()
                        ->title('Reply sent successfully')
                        ->success()
                        ->send();

                    $this->fillForm();
                }),

            Actions\Action::make('assign_to_me')
                ->label('Assign to Me')
                ->icon('heroicon-o-user-plus')
                ->color('success')
                ->action(function (SupportChat $record): void {
                    SupportChat::where('user_id', $record->user_id)
                        ->update(['admin_id' => auth()->id()]);

                    Notification::make()
                        ->title('Conversation assigned to you')
                        ->success()
                        ->send();
                })
                ->visible(function (SupportChat $record): bool {
                    $authId = auth()->id();
                    
                    // If assigned to me directly on this record, hide it.
                    if ($record->admin_id == $authId) {
                        return false;
                    }

                    // If assigned to someone else, show it (allow taking over).
                    if ($record->admin_id && $record->admin_id != $authId) {
                        return true;
                    }

                    // If unassigned on this specific record, check if I am already handling this user's chat.
                    // The assignment action updates based on user_id, so we should check based on user_id too.
                    return !SupportChat::where('user_id', $record->user_id)
                        ->where('admin_id', $authId)
                        ->exists();
                }),

            Actions\Action::make('mark_read')
                ->label('Mark as Read')
                ->icon('heroicon-o-check')
                ->color('success')
                ->action(function (SupportChat $record): void {
                    $record->markAsRead();

                    Notification::make()
                        ->title('Message marked as read')
                        ->success()
                        ->send();
                })
                ->visible(fn(SupportChat $record): bool => !$record->is_read),

            Actions\Action::make('close_conversation')
                ->label('Close Conversation')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->action(function (SupportChat $record): void {
                    SupportChat::where('user_id', $record->user_id)
                        ->update(['status' => 'closed']);

                    Notification::make()
                        ->title('Conversation closed')
                        ->success()
                        ->send();
                })
                ->visible(fn(SupportChat $record): bool => $record->status !== 'closed'),

            Actions\Action::make('reopen_conversation')
                ->label('Reopen Conversation')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->action(function (SupportChat $record): void {
                    SupportChat::where('user_id', $record->user_id)
                        ->update(['status' => 'open']);

                    Notification::make()
                        ->title('Conversation reopened')
                        ->success()
                        ->send();
                })
                ->visible(fn(SupportChat $record): bool => $record->status === 'closed'),
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [

        ];
    }

    public function infolist(Schema $schema): Schema
    {
        $record = $this->record;
        $components = [
            \Filament\Schemas\Components\Section::make('Support Chat Details')
                ->schema([
                    Infolists\Components\TextEntry::make('user.name')
                        ->label('Customer'),
                    
                    Infolists\Components\TextEntry::make('admin.name')
                        ->label('Assigned Admin')
                        ->placeholder('Unassigned'),
                    
                    Infolists\Components\TextEntry::make('message')
                        ->label('Message')
                        ->words(10)
                        ->hidden()
                        ->columnSpanFull(),
                    
                    Infolists\Components\TextEntry::make('message_type')
                        ->label('Message Type')
                        ->badge(),
                    
                    Infolists\Components\TextEntry::make('subject')
                        ->label('Subject'),
                    
                    Infolists\Components\TextEntry::make('priority')
                        ->label('Priority')
                        ->badge()
                        ->color(fn(string $state): string => match ($state) {
                            'low' => 'success',
                            'medium' => 'primary',
                            'high' => 'warning',
                            'urgent' => 'danger',
                            default => 'gray',
                        }),
                    
                    Infolists\Components\TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->color(fn(string $state): string => match ($state) {
                            'open' => 'success',
                            'pending' => 'warning',
                            'closed' => 'danger',
                            default => 'gray',
                        }),
                    
                    Infolists\Components\TextEntry::make('booking.booking_code')
                        ->label('Booking Code')
                        ->placeholder('No booking'),
                    
                    Infolists\Components\TextEntry::make('created_at')
                        ->label('Created At')
                        ->dateTime(),
                    
                    Infolists\Components\TextEntry::make('read_at')
                        ->label('Read At')
                        ->dateTime()
                        ->placeholder('Not read yet'),
                ])
                ->columns(2),
        ];

        if ($record->metadata && isset($record->metadata['refund_request_id'])) {
            $refundRequest = RefundRequest::with(['processedBy', 'booking'])->find($record->metadata['refund_request_id']);
            
            if ($refundRequest) {
                $components[] = \Filament\Schemas\Components\Section::make('Refund Request Information')
                    ->schema([
                        Infolists\Components\TextEntry::make('refund_request_id')
                            ->label('Refund Request ID')
                            ->state($refundRequest->id),
                        
                        Infolists\Components\TextEntry::make('refund_reason')
                            ->label('Reason')
                            ->state($refundRequest->reason),
                        
                        Infolists\Components\TextEntry::make('refund_description')
                            ->label('Description')
                            ->state($refundRequest->description)
                            ->columnSpanFull(),
                        
                        Infolists\Components\TextEntry::make('requested_amount')
                            ->label('Requested Amount')
                            ->state('₹' . number_format($refundRequest->requested_amount, 2))
                            ->weight('bold'),
                        
                        Infolists\Components\TextEntry::make('approved_amount')
                            ->label('Approved Amount')
                            ->state($refundRequest->approved_amount ? '₹' . number_format($refundRequest->approved_amount, 2) : 'N/A')
                            ->weight('bold')
                            ->color(fn() => $refundRequest->approved_amount ? 'success' : 'gray'),
                        
                        Infolists\Components\TextEntry::make('refund_status')
                            ->label('Status')
                            ->state(ucfirst(str_replace('_', ' ', $refundRequest->status)))
                            ->badge()
                            ->color(fn() => match ($refundRequest->status) {
                                'pending' => 'warning',
                                'approved' => 'success',
                                'partially_approved' => 'info',
                                'rejected' => 'danger',
                                default => 'gray',
                            }),
                        
                        Infolists\Components\TextEntry::make('refund_source')
                            ->label('Refund Source')
                            ->state($refundRequest->refund_source ? ucfirst(str_replace('_', ' ', $refundRequest->refund_source)) : 'N/A'),
                        
                        Infolists\Components\TextEntry::make('admin_notes')
                            ->label('Admin Notes')
                            ->state($refundRequest->admin_notes ?: 'No notes')
                            ->columnSpanFull(),
                        
                        Infolists\Components\TextEntry::make('processed_by')
                            ->label('Processed By')
                            ->state($refundRequest->processedBy ? $refundRequest->processedBy->name : 'Not processed yet'),
                        
                        Infolists\Components\TextEntry::make('processed_at')
                            ->label('Processed At')
                            ->state($refundRequest->processed_at ? $refundRequest->processed_at->format('Y-m-d H:i:s') : 'N/A'),
                        
                        Infolists\Components\TextEntry::make('booking_code')
                            ->label('Booking Code')
                            ->state($refundRequest->booking ? $refundRequest->booking->booking_code : 'N/A'),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->collapsed(false);
            }
        }

        return $schema->schema($components);
    }
}
