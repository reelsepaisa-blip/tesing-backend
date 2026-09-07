<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold">Support Chat History</h3>
                <div class="flex space-x-2">
                    @if ($this->record && !$this->record->admin_id)
                        <x-filament::button wire:click="assignToMe" color="success" size="sm"
                            style="margin-right: 5px;">
                            Assign to Me
                        </x-filament::button>
                    @endif

                    @if ($this->record && $this->record->status !== 'closed')
                        <x-filament::button wire:click="closeConversation" color="danger" size="sm">
                            Close Conversation
                        </x-filament::button>
                    @elseif($this->record && $this->record->status === 'closed')
                        <x-filament::button wire:click="reopenConversation" color="warning" size="sm">
                            Reopen Conversation
                        </x-filament::button>
                    @endif
                </div>
            </div>
        </x-slot>

        <div class="space-y-6">
            <!-- Conversation Stats -->
            <div class="grid grid-cols-2 gap-4 d-flex" style="display: flex;">
                <!-- Row 1 -->
                <div class="bg-gray-50 p-4 rounded-lg" style="width: 50%;">
                    <div class="text-sm text-gray-600">Total Messages</div>
                    <div class="text-2xl font-bold">{{ $this->getConversationStats()['total_messages'] }}</div>
                </div>
                <div class="bg-gray-50 p-4 rounded-lg" style="width: 50%;">
                    <div class="text-sm text-blue-600">Customer Messages</div>
                    <div class="text-2xl font-bold text-blue-800">{{ $this->getConversationStats()['user_messages'] }}
                    </div>
                </div>

                <!-- Row 2 -->
                <div class="bg-gray-50 p-4 rounded-lg" style="width: 50%;">
                    <div class="text-sm text-green-600">Admin Messages</div>
                    <div class="text-2xl font-bold text-green-800">{{ $this->getConversationStats()['admin_messages'] }}
                    </div>
                </div>
                <div class="bg-gray-50 p-4 rounded-lg" style="width: 50%;">
                    <div class="text-sm text-red-600">Unread</div>
                    <div class="text-2xl font-bold text-red-800">{{ $this->getConversationStats()['unread_messages'] }}
                    </div>
                </div>
            </div>

            {{-- <!-- Conversation Info -->
            <div class="bg-gray-50 p-4 rounded-lg d-flex">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div>
                        <span class="font-medium">Customer:</span>
                        <span>{{ $this->getConversationStats()['customer'] }}</span>
                    </div>
                    <div>
                        <span class="font-medium">Assigned Admin:</span>
                        <span>{{ $this->getConversationStats()['assigned_admin'] }}</span>
                    </div>
                    <div>
                        <span class="font-medium">Status:</span>
                        <span
                            class="px-2 py-1 rounded text-xs font-medium
                            @if ($this->getConversationStats()['conversation_status'] === 'open') bg-green-100 text-green-800
                            @elseif($this->getConversationStats()['conversation_status'] === 'pending') bg-yellow-100 text-yellow-800
                            @else bg-red-100 text-red-800 @endif">
                            {{ ucfirst($this->getConversationStats()['conversation_status']) }}
                        </span>
                    </div>
                    <div>
                        <span class="font-medium">Priority:</span>
                        <span
                            class="px-2 py-1 rounded text-xs font-medium
                            @if ($this->getConversationStats()['priority'] === 'urgent') bg-red-100 text-red-800
                            @elseif($this->getConversationStats()['priority'] === 'high') bg-orange-100 text-orange-800
                            @elseif($this->getConversationStats()['priority'] === 'medium') bg-blue-100 text-blue-800
                            @else bg-green-100 text-green-800 @endif">
                            {{ ucfirst($this->getConversationStats()['priority']) }}
                        </span>
                    </div>
                </div>
            </div> --}}

            <!-- WhatsApp-style Chat Container -->
            <div
                style="background-color: #f3f4f6; border-radius: 8px; overflow: hidden; height: 500px; display: flex; flex-direction: column;">
                <!-- Chat Header -->
                <div
                    style="background-color: #F1A309; color: white; padding: 16px; display: flex; align-items: center; justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div
                            style="width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background-color: #b24439;">
                            <span
                                style="color: white; font-weight: bold;">{{ substr($this->record->user->name ?? 'U', 0, 1) }}</span>
                        </div>
                        <div>
                            <h3 style="font-weight: 600; margin: 0;">{{ $this->record->user->name ?? 'Customer' }}</h3>
                            <p style="font-size: 12px; opacity: 0.75; margin: 0;">{{ $this->record->user->phone ?? '' }}
                            </p>
                        </div>
                    </div>
                    {{-- <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="width: 8px; height: 8px; background-color: ; border-radius: 50%;"></span>
                    </div> --}}
                </div>

                <!-- Chat Messages Area -->
                <div
                    style="flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 12px; background: linear-gradient(to bottom, #e5ddd5, #f7f3f0);">
                    @foreach ($this->getConversationMessages() as $message)
                        <div
                            style="display: flex; {{ $message->sender_type === 'admin' ? 'justify-content: flex-end;' : 'justify-content: flex-start;' }}">
                            <div
                                style="max-width: 300px; min-width: 100px; padding: 12px; border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.1); word-wrap: break-word; overflow-wrap: break-word; {{ $message->sender_type === 'admin' ? 'background-color: #FFFBEB; color: #374151; border-bottom-right-radius: 4px; border: 1px solid #f3f4f6;' : 'background-color: #F1A309; color: white; border-bottom-left-radius: 4px;' }}">

                                <!-- Message Content -->
                                <div style="font-size: 14px; word-wrap: break-word; overflow-wrap: break-word; word-break: break-word;">
                                    @if ($message->message_type === 'image' && $message->metadata && is_array($message->metadata))
                                        <div style="margin-bottom: 8px;">
                                            <img src="{{ $message->metadata['image_url'] ?? asset('storage/' . ($message->metadata['image_path'] ?? '')) }}"
                                                alt="Image"
                                                style="max-width: 100%; height: auto; border-radius: 4px;">
                                        </div>
                                    @endif
                                    <p style="margin: 0; word-wrap: break-word; overflow-wrap: break-word; word-break: break-word; white-space: pre-wrap;">{{ is_string($message->message) ? $message->message : json_encode($message->message) }}</p>
                                </div>

                                <!-- Message Footer -->
                                <div
                                    style="display: flex; align-items: center; justify-content: flex-end; margin-top: 4px; font-size: 12px; opacity: 0.75;">
                                    <span style="color: #6b7280;">
                                        {{ $message->created_at->format('H:i') }}
                                    </span>
                                    @if ($message->sender_type === 'admin')
                                        <span
                                            style="margin-left: 4px; {{ $message->is_read ? 'color: #3b82f6;' : 'color: #f59e0b;' }}">
                                            {{ $message->is_read ? '✓✓' : '✓' }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <!-- WhatsApp-style Input Area -->
                <div style="background-color: white; border-top: 1px solid #e5e7eb; padding: 16px;">
                    <form wire:submit="sendReply" style="display: flex; align-items: center; gap: 12px;">


                        <!-- Message Input -->
                        <div style="flex: 1;">
                            <input wire:model.lazy="message" type="text" placeholder="Type a message..."
                                style="width: 100%; border: 1px solid #d1d5db; border-radius: 20px; padding: 8px 16px; background-color: #f9fafb; outline: none;"
                                required />
                        </div>

                        <!-- Send Button -->
                        <button type="submit"
                            style="flex-shrink: 0; background-color: #22c55e; color: white; border: none; border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: background-color 0.2s;">
                            <svg style="width: 20px; height: 20px;" fill="currentColor" viewBox="0 0 20 20">
                                <path
                                    d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.408l-7-14z">
                                </path>
                            </svg>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
