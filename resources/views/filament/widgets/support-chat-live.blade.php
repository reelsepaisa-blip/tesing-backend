<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold">Live Support Chat</h3>
                <div class="flex space-x-2">
                    <x-filament::badge color="success">
                        Online
                    </x-filament::badge>
                </div>
            </div>
        </x-slot>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Left Column: Send Message Form -->
            {{-- <div class="lg:col-span-1">
                <div class="bg-white border rounded-lg p-4">
                    <h4 class="font-medium mb-4">Send Message to Customer</h4>

                    <form wire:submit="sendMessage" class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Select Customer</label>
                            <select wire:model.lazy="selectedUser"
                                class="w-full border-gray-300 rounded-md shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                <option value="">Select a customer...</option>
                                @foreach (\App\Models\User::where('role_id', 3)->get() as $user)
                                    <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->phone }})
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Message</label>
                            <textarea wire:model.lazy="message"
                                class="w-full border-gray-300 rounded-md shadow-sm focus:border-primary-500 focus:ring-primary-500" rows="3"
                                placeholder="Type your message here..."></textarea>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1"
                                    style="margin-bottom: 10px;">Message Type</label>
                                <select wire:model.lazy="messageType"
                                    class="w-full border-gray-300 rounded-md shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                    <option value="text">Text</option>
                                    <option value="image">Image</option>
                                    <option value="file">File</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1"
                                    style="margin-bottom: 10px;">Priority</label>
                                <select wire:model.lazy="priority"
                                    class="w-full border-gray-300 rounded-md shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                    <option value="low">Low</option>
                                    <option value="medium">Medium</option>
                                    <option value="high">High</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>
                        </div>

                        @if (in_array($this->messageType, ['image', 'file']))
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Attachment</label>
                                <input wire:model.defer="attachment" type="file" accept="image/*,application/pdf,text/*"
                                    class="w-full border-gray-300 rounded-md shadow-sm focus:border-primary-500 focus:ring-primary-500">
                            </div>
                        @endif

                        <div class="flex justify-end">
                            <x-filament::button type="submit" color="primary">
                                Send Message
                            </x-filament::button>
                        </div>
                    </form>
                </div>
            </div> --}}

            <!-- Right Column: Conversations -->
            {{-- <div class="lg:col-span-2">
                <div class="space-y-4">
                    <!-- Unassigned Conversations -->
                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                        <h4 class="font-medium text-yellow-800 mb-3">Unassigned Conversations</h4>
                        <div class="space-y-2">
                            @foreach ($this->getUnassignedConversations() as $conversation)
                                <div class="flex items-center justify-between bg-white p-3 rounded border">
                                    <div>
                                        <div class="font-medium">{{ $conversation->user->name }}</div>
                                        <div class="text-sm text-gray-600">{{ $conversation->user->phone }}</div>
                                        <div class="text-xs text-gray-500">
                                            {{ $conversation->created_at->diffForHumans() }}</div>
                                    </div>
                                    <x-filament::button wire:click="assignToMe({{ $conversation->user_id }})"
                                        color="success" size="sm">
                                        Assign to Me
                                    </x-filament::button>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <!-- My Assigned Conversations -->
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                        <h4 class="font-medium text-blue-800 mb-3">My Assigned Conversations</h4>
                        <div class="space-y-2">
                            @foreach ($this->getMyAssignedConversations() as $conversation)
                                <div class="flex items-center justify-between bg-white p-3 rounded border">
                                    <div>
                                        <div class="font-medium">{{ $conversation->user->name }}</div>
                                        <div class="text-sm text-gray-600">{{ $conversation->user->phone }}</div>
                                        <div class="text-xs text-gray-500">
                                            {{ $conversation->created_at->diffForHumans() }}</div>
                                    </div>
                                    <x-filament::button
                                        href="{{ route('filament.admin.resources.support-chats.view', $conversation) }}"
                                        color="primary" size="sm">
                                        View Chat
                                    </x-filament::button>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <!-- Recent Messages -->
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                        <h4 class="font-medium text-gray-800 mb-3">Recent Messages</h4>
                        <div class="space-y-2 max-h-64 overflow-y-auto">
                            @foreach ($this->getRecentMessages() as $message)
                                <div class="flex items-start space-x-3 bg-white p-3 rounded border">
                                    <div class="flex-shrink-0">
                                        <div class="w-8 h-8 bg-gray-300 rounded-full flex items-center justify-center">
                                            <span class="text-xs font-medium">
                                                {{ substr($message->sender->name, 0, 1) }}
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center space-x-2">
                                            <span class="font-medium text-sm">{{ $message->sender->name }}</span>
                                            <span class="text-xs text-gray-500">
                                                {{ $message->created_at->format('H:i') }}
                                            </span>
                                            @if ($message->sender_type === 'admin')
                                                <x-filament::badge color="success"
                                                    size="xs">Admin</x-filament::badge>
                                            @else
                                                <x-filament::badge color="primary"
                                                    size="xs">Customer</x-filament::badge>
                                            @endif
                                        </div>
                                        <p class="text-sm text-gray-600 mt-1">{{ Str::limit($message->message, 100) }}
                                        </p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div> --}}
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
