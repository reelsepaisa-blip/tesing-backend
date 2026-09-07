<?php

namespace App\Filament\Widgets;

use App\Models\SupportTicket;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SupportStats extends BaseWidget
{
    protected ?string $pollingInterval = '15s';

    protected function getStats(): array
    {
        $tickets = SupportTicket::query();
        $openTickets = $tickets->clone()->whereNull('closed_at');

        return [
            Stat::make('Open Tickets', $openTickets->count())
                ->description('Tickets requiring attention')
                ->descriptionIcon('heroicon-m-ticket')
                ->chart([
                    $openTickets->clone()->where('priority', SupportTicket::PRIORITY_LOW)->count(),
                    $openTickets->clone()->where('priority', SupportTicket::PRIORITY_MEDIUM)->count(),
                    $openTickets->clone()->where('priority', SupportTicket::PRIORITY_HIGH)->count(),
                    $openTickets->clone()->where('priority', SupportTicket::PRIORITY_URGENT)->count(),
                ])
                ->color('warning'),

            Stat::make('Needs Attention', $openTickets->clone()->needsAttention()->count())
                ->description('No response in 24 hours')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->chart([
                    $openTickets->clone()->needsAttention()->where('priority', SupportTicket::PRIORITY_LOW)->count(),
                    $openTickets->clone()->needsAttention()->where('priority', SupportTicket::PRIORITY_MEDIUM)->count(),
                    $openTickets->clone()->needsAttention()->where('priority', SupportTicket::PRIORITY_HIGH)->count(),
                    $openTickets->clone()->needsAttention()->where('priority', SupportTicket::PRIORITY_URGENT)->count(),
                ])
                ->color('danger'),

            Stat::make('Unassigned', $openTickets->clone()->whereNull('assigned_to')->count())
                ->description('Tickets without agent')
                ->descriptionIcon('heroicon-m-user')
                ->chart([
                    $openTickets->clone()->whereNull('assigned_to')->where('priority', SupportTicket::PRIORITY_LOW)->count(),
                    $openTickets->clone()->whereNull('assigned_to')->where('priority', SupportTicket::PRIORITY_MEDIUM)->count(),
                    $openTickets->clone()->whereNull('assigned_to')->where('priority', SupportTicket::PRIORITY_HIGH)->count(),
                    $openTickets->clone()->whereNull('assigned_to')->where('priority', SupportTicket::PRIORITY_URGENT)->count(),
                ])
                ->color('info'),
        ];
    }
}



