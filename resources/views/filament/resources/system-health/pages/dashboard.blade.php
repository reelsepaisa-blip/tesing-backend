<x-filament-panels::page>
    @php
        $metrics = $this->getSystemMetrics();
        $criticalIssues = $this->getCriticalIssues();
        $recentEvents = $this->getRecentEvents();
    @endphp

    <!-- System Overview Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
        <!-- Active Bookings -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Active Bookings</p>
                    <p class="text-3xl font-bold text-green-600">{{ $metrics['active_bookings'] }}</p>
                </div>
                <div class="p-3 bg-green-100 dark:bg-green-900 rounded-full">
                    <x-heroicon-o-calendar class="w-6 h-6 text-green-600 dark:text-green-400" />
                </div>
            </div>
        </div>

        <!-- Online Drivers -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Online Drivers</p>
                    <p class="text-3xl font-bold text-blue-600">{{ $metrics['online_drivers'] }}</p>
                </div>
                <div class="p-3 bg-blue-100 dark:bg-blue-900 rounded-full">
                    <x-heroicon-o-user-group class="w-6 h-6 text-blue-600 dark:text-blue-400" />
                </div>
            </div>
        </div>

        <!-- Failed Jobs -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Failed Jobs</p>
                    <p class="text-3xl font-bold {{ $metrics['failed_jobs'] > 0 ? 'text-red-600' : 'text-green-600' }}">
                        {{ $metrics['failed_jobs'] }}
                    </p>
                </div>
                <div
                    class="p-3 {{ $metrics['failed_jobs'] > 0 ? 'bg-red-100 dark:bg-red-900' : 'bg-green-100 dark:bg-green-900' }} rounded-full">
                    <x-heroicon-o-exclamation-triangle
                        class="w-6 h-6 {{ $metrics['failed_jobs'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}" />
                </div>
            </div>
        </div>

        <!-- Queue Size -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Queue Size</p>
                    <p class="text-3xl font-bold text-purple-600">{{ $metrics['queue_size'] }}</p>
                </div>
                <div class="p-3 bg-purple-100 dark:bg-purple-900 rounded-full">
                    <x-heroicon-o-queue-list class="w-6 h-6 text-purple-600 dark:text-purple-400" />
                </div>
            </div>
        </div>
    </div>

    <!-- Critical Issues Section -->
    @if (count($criticalIssues) > 0)
        <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-6 mb-6">
            <div class="flex items-center mb-4">
                <x-heroicon-o-exclamation-triangle class="w-6 h-6 text-red-600 dark:text-red-400 mr-2" />
                <h3 class="text-lg font-semibold text-red-900 dark:text-red-100">Critical Issues Detected</h3>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @foreach ($criticalIssues as $issue)
                    <div
                        class="bg-white dark:bg-gray-800 rounded-lg p-4 border-l-4 {{ $issue['severity'] === 'critical' ? 'border-red-500' : ($issue['severity'] === 'high' ? 'border-orange-500' : 'border-yellow-500') }}">
                        <div class="flex justify-between items-start mb-2">
                            <h4 class="font-semibold text-gray-900 dark:text-gray-100">{{ $issue['type'] }}</h4>
                            <span
                                class="px-2 py-1 text-xs font-medium rounded-full {{ $issue['severity'] === 'critical' ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' : ($issue['severity'] === 'high' ? 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200' : 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200') }}">
                                {{ ucfirst($issue['severity']) }}
                            </span>
                        </div>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">{{ $issue['description'] }}</p>
                        <div class="flex justify-between items-center">
                            <span class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ $issue['count'] }}
                                issues</span>
                            <a href="{{ $issue['action_url'] }}"
                                class="text-sm text-blue-600 dark:text-blue-400 hover:underline">
                                View Details →
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @else
        <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-6 mb-6">
            <div class="flex items-center">
                <x-heroicon-o-check-circle class="w-6 h-6 text-green-600 dark:text-green-400 mr-2" />
                <h3 class="text-lg font-semibold text-green-900 dark:text-green-100">All Systems Operational</h3>
            </div>
            <p class="text-green-700 dark:text-green-300 mt-2">No critical issues detected. System is running normally.
            </p>
        </div>
    @endif

    <!-- Detailed Metrics Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- System Metrics -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">System Metrics</h3>
            </div>
            <div class="p-6">
                <div class="space-y-4">
                    <div class="flex justify-between items-center">
                        <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Stuck Bookings</span>
                        <span
                            class="text-sm font-bold {{ $metrics['stuck_bookings'] > 0 ? 'text-red-600' : 'text-green-600' }}">
                            {{ $metrics['stuck_bookings'] }}
                        </span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Failed Transactions
                            Today</span>
                        <span
                            class="text-sm font-bold {{ $metrics['failed_transactions_today'] > 5 ? 'text-red-600' : 'text-green-600' }}">
                            {{ $metrics['failed_transactions_today'] }}
                        </span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Offline Drivers</span>
                        <span
                            class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ $metrics['offline_drivers'] }}</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Pending Support
                            Tickets</span>
                        <span
                            class="text-sm font-bold {{ $metrics['pending_support_tickets'] > 10 ? 'text-orange-600' : 'text-green-600' }}">
                            {{ $metrics['pending_support_tickets'] }}
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Events -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Recent System Events</h3>
            </div>
            <div class="p-6">
                @if (count($recentEvents) > 0)
                    <div class="space-y-4">
                        @foreach ($recentEvents as $event)
                            <div class="flex items-start space-x-3">
                                <div class="flex-shrink-0">
                                    @if ($event['severity'] === 'error')
                                        <div class="w-2 h-2 bg-red-500 rounded-full mt-2"></div>
                                    @elseif($event['severity'] === 'warning')
                                        <div class="w-2 h-2 bg-orange-500 rounded-full mt-2"></div>
                                    @else
                                        <div class="w-2 h-2 bg-blue-500 rounded-full mt-2"></div>
                                    @endif
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                        {{ $event['title'] }}</p>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $event['description'] }}</p>
                                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                                        {{ $event['timestamp']->diffForHumans() }}
                                    </p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-gray-500 dark:text-gray-400 text-center py-8">No recent system events</p>
                @endif
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="mt-6 bg-white dark:bg-gray-800 rounded-lg shadow">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Quick Actions</h3>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <a href="/admin/bookings?tableFilters[status][values][0]=searching"
                    class="flex items-center justify-center px-4 py-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg hover:bg-blue-100 dark:hover:bg-blue-900/30 transition-colors">
                    <x-heroicon-o-magnifying-glass class="w-5 h-5 text-blue-600 dark:text-blue-400 mr-2" />
                    <span class="text-sm font-medium text-blue-900 dark:text-blue-100">View Searching Bookings</span>
                </a>

                <a href="/admin/transactions?tableFilters[status][values][0]=failed"
                    class="flex items-center justify-center px-4 py-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg hover:bg-red-100 dark:hover:bg-red-900/30 transition-colors">
                    <x-heroicon-o-exclamation-triangle class="w-5 h-5 text-red-600 dark:text-red-400 mr-2" />
                    <span class="text-sm font-medium text-red-900 dark:text-red-100">View Failed Transactions</span>
                </a>

                <a href="/admin/support-tickets?tableFilters[status][values][0]=open"
                    class="flex items-center justify-center px-4 py-3 bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800 rounded-lg hover:bg-orange-100 dark:hover:bg-orange-900/30 transition-colors">
                    <x-heroicon-o-ticket class="w-5 h-5 text-orange-600 dark:text-orange-400 mr-2" />
                    <span class="text-sm font-medium text-orange-900 dark:text-orange-100">View Support Tickets</span>
                </a>

                <a href="/admin/drivers?tableFilters[is_online][value]=false"
                    class="flex items-center justify-center px-4 py-3 bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-800 rounded-lg hover:bg-purple-100 dark:hover:bg-purple-900/30 transition-colors">
                    <x-heroicon-o-user-group class="w-5 h-5 text-purple-600 dark:text-purple-400 mr-2" />
                    <span class="text-sm font-medium text-purple-900 dark:text-purple-100">View Offline Drivers</span>
                </a>
            </div>
        </div>
    </div>
</x-filament-panels::page>
