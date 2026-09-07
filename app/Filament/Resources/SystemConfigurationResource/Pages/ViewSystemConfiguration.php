<?php

namespace App\Filament\Resources\SystemConfigurationResource\Pages;

use App\Filament\Resources\SystemConfigurationResource;
use Filament\Actions;
use App\Filament\Resources\Pages\ViewRecord;
use Filament\Infolists;
use Filament\Schemas\Schema;

class ViewSystemConfiguration extends ViewRecord
{
    protected static string $resource = SystemConfigurationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                \Filament\Schemas\Components\Section::make('Configuration Details')
                    ->schema([
                        Infolists\Components\TextEntry::make('category')
                            ->badge()
                            ->color(fn(string $state): string => match ($state) {
                                'payment' => 'success',
                                'notification' => 'info',
                                'sms' => 'warning',
                                'maps' => 'primary',
                                'social' => 'secondary',
                                default => 'gray',
                            }),

                        Infolists\Components\TextEntry::make('key')
                            ->weight('medium'),

                        Infolists\Components\TextEntry::make('value')
                            ->formatStateUsing(function ($state, $record) {
                                if ($record->is_encrypted) {
                                    return str_repeat('*', min(strlen($state), 40)) . ' (Encrypted)';
                                }
                                return $state;
                            })
                            ->copyable(fn($record) => !$record->is_encrypted),

                        Infolists\Components\TextEntry::make('description')
                            ->placeholder('No description provided'),

                        Infolists\Components\IconEntry::make('is_encrypted')
                            ->label('Encrypted')
                            ->boolean()
                            ->trueIcon('heroicon-o-lock-closed')
                            ->falseIcon('heroicon-o-lock-open'),

                        Infolists\Components\IconEntry::make('is_active')
                            ->label('Active')
                            ->boolean()
                            ->trueIcon('heroicon-o-check-circle')
                            ->falseIcon('heroicon-o-x-circle')
                            ->trueColor('success')
                            ->falseColor('danger'),
                    ])
                    ->columns(2),

                \Filament\Schemas\Components\Section::make('Metadata')
                    ->schema([
                        Infolists\Components\TextEntry::make('created_at')
                            ->dateTime(),

                        Infolists\Components\TextEntry::make('updated_at')
                            ->dateTime(),
                    ])
                    ->columns(2)
                    ->collapsible(),
            ]);
    }
}
