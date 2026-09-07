<?php

namespace App\Filament\Widgets;

use App\Models\SupportChat;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class SupportChatStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $today = now()->startOfDay();
        $thisWeek = now()->startOfWeek();
        $thisMonth = now()->startOfMonth();

        $totalConversations = SupportChat::whereNotNull('booking_id')
            ->distinct('booking_id')
            ->count();

        $openConversations = SupportChat::where('status', 'open')
            ->whereNotNull('booking_id')
            ->distinct('booking_id')
            ->count();

        $unreadMessages = SupportChat::where('is_read', false)
            ->where('sender_type', 'user')
            ->count();

        $todayMessages = SupportChat::whereDate('created_at', $today)->count();

        $weekMessages = SupportChat::where('created_at', '>=', $thisWeek)->count();

        $monthMessages = SupportChat::where('created_at', '>=', $thisMonth)->count();

        $urgentMessages = SupportChat::where('priority', 'urgent')
            ->where('status', '!=', 'closed')
            ->count();

        $avgResponseTime = SupportChat::where('sender_type', 'admin')
            ->whereNotNull('created_at')
            ->whereNotNull('booking_id')
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR,
                (SELECT MAX(created_at) FROM support_chats s2
                 WHERE s2.booking_id = support_chats.booking_id
                 AND s2.sender_type = "user"
                 AND s2.created_at < support_chats.created_at),
                created_at)) as avg_response_time')
            ->value('avg_response_time');

        return [
            Stat::make('Total Conversations', $totalConversations)
                ->description('Unique booking conversations')
                ->descriptionIcon('heroicon-m-chat-bubble-left-right')
                ->color('primary'),

            Stat::make('Open Conversations', $openConversations)
                ->description('Active booking conversations')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($openConversations > 10 ? 'danger' : 'success'),

            Stat::make('Unread Messages', $unreadMessages)
                ->description('Messages waiting for admin response')
                ->descriptionIcon('heroicon-m-envelope')
                ->color($unreadMessages > 5 ? 'danger' : 'warning'),

            Stat::make('Urgent Messages', $urgentMessages)
                ->description('High priority messages')
                ->descriptionIcon('heroicon-m-fire')
                ->color($urgentMessages > 0 ? 'danger' : 'success'),

            Stat::make("Today's Messages", $todayMessages)
                ->description('Messages received today')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('info'),

            Stat::make('This Week', $weekMessages)
                ->description('Messages this week')
                ->descriptionIcon('heroicon-m-calendar')
                ->color('info'),

            Stat::make('This Month', $monthMessages)
                ->description('Messages this month')
                ->descriptionIcon('heroicon-m-calendar')
                ->color('info'),

            Stat::make('Avg Response Time', $avgResponseTime ? round($avgResponseTime, 1) . 'h' : 'N/A')
                ->description('Average admin response time')
                ->descriptionIcon('heroicon-m-clock')
                ->color($avgResponseTime && $avgResponseTime > 24 ? 'danger' : 'success'),
        ];
    }
}
