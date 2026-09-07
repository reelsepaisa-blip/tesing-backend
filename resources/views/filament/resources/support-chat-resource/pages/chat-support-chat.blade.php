<x-filament-panels::page>
    <div class="space-y-6">
        <!-- Chat Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4" style="display: flex;">
            <div class="bg-white rounded-lg p-4 shadow-sm border" style="width: 25%;">
                <div class="text-2xl font-bold text-gray-900">{{ $this->getConversationStats()['total_messages'] }}</div>
                <div class="text-sm text-gray-600">Total Messages</div>
            </div>
            <div class="bg-white rounded-lg p-4 shadow-sm border" style="width: 25%;">
                <div class="text-2xl font-bold text-blue-600">{{ $this->getConversationStats()['user_messages'] }}</div>
                <div class="text-sm text-gray-600">Customer Messages</div>
            </div>
            <div class="bg-white rounded-lg p-4 shadow-sm border" style="width: 25%;">
                <div class="text-2xl font-bold text-green-600">{{ $this->getConversationStats()['admin_messages'] }}
                </div>
                <div class="text-sm text-gray-600">Admin Messages</div>
            </div>
            <div class="bg-white rounded-lg p-4 shadow-sm border" style="width: 25%;">
                <div class="text-2xl font-bold text-red-600">{{ $this->getConversationStats()['unread_messages'] }}
                </div>
                <div class="text-sm text-gray-600">Unread</div>
            </div>
        </div>

        <!-- Individual WhatsApp-style Chat Interface -->
        <div class="bg-white rounded-lg shadow-sm border overflow-hidden">
            <div
                style="background-color: #f3f4f6; border-radius: 8px; overflow: hidden; height: 500px; display: flex; flex-direction: column;">
                <!-- Chat Header -->
                <div
                    style="background-color: #F1A309; color: white; padding: 16px; display: flex; align-items: center; justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div
                            style="width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background-color: #b24439;">
                            <span
                                style="color: white; font-weight: bold;">{{ $this->record->booking_id ? substr($this->record->booking->booking_code ?? 'B', 0, 1) : substr($this->record->user->name ?? 'U', 0, 1) }}</span>
                        </div>
                        <div>
                            @if($this->record->booking_id && $this->record->booking)
                                <h3 style="font-weight: 700; margin: 0; font-size: 16px;">Booking: {{ $this->record->booking->booking_code }}</h3>
                                <p style="font-size: 12px; opacity: 0.85; margin: 0;">
                                    Customer: {{ $this->record->user->name ?? 'N/A' }}
                                    @if($this->record->user->phone)
                                        | {{ $this->record->user->phone }}
                                    @endif
                                </p>
                            @else
                                <h3 style="font-weight: 600; margin: 0;">{{ $this->record->user->name ?? 'Customer' }}</h3>
                                <p style="font-size: 12px; opacity: 0.75; margin: 0;">{{ $this->record->user->phone ?? '' }}
                                </p>
                            @endif
                        </div>
                    </div>
                </div>

                <!-- Chat Messages Area -->
                <div
                    style="flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 12px; background: linear-gradient(to bottom, #e5ddd5, #f7f3f0);">
                    @foreach ($this->getConversationMessages() as $message)
                        <div
                            style="display: flex; {{ $message->sender_type === 'admin' ? 'justify-content: flex-end;' : 'justify-content: flex-start;' }}">
                            <div
                                style="max-width: 70%; min-width: 100px; padding: 12px; border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.1); word-wrap: break-word; overflow-wrap: break-word; {{ $message->sender_type === 'admin' ? 'background-color: #FFFBEB; color: #374151; border-bottom-right-radius: 4px; border: 1px solid #f3f4f6;' : 'background-color: #FFFBEB; color: black; border-bottom-left-radius: 4px;' }}">

                                <!-- Message Content -->
                                <div style="font-size: 14px; word-wrap: break-word; overflow-wrap: break-word; word-break: break-word;">
                                    @if ($message->message_type === 'image' && $message->metadata && is_array($message->metadata))
                                        <div style="margin-bottom: 8px;">
                                            <img src="{{ $message->metadata['image_url'] ?? asset('storage/' . ($message->metadata['image_path'] ?? '')) }}"
                                                alt="Image"
                                                style="max-width: 100%; height: auto; border-radius: 4px;">
                                        </div>
                                    @endif
                                    <p style="margin: 0; word-wrap: break-word; overflow-wrap: break-word; word-break: break-word;">
                                        {{ is_string($message->message) ? $message->message : json_encode($message->message) }}
                                    </p>
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
    </div>

    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Wait for Echo to be available
                function initializeChatWebSocket() {
                    if (typeof window.Echo === 'undefined') {
                        // If Echo is not available, try to initialize Pusher directly
                        if (typeof window.Pusher === 'undefined') {
                            console.warn('Pusher is not loaded. Loading from CDN...');
                            const pusherScript = document.createElement('script');
                            pusherScript.src = 'https://js.pusher.com/8.2.0/pusher.min.js';
                            pusherScript.onload = function() {
                                initializePusherDirectly();
                            };
                            document.head.appendChild(pusherScript);
                            return;
                        } else {
                            initializePusherDirectly();
                            return;
                        }
                    }

                    // Use Echo if available
                    const adminId = {{ auth()->id() }};
                    const bookingId = {{ $this->record->booking_id ?? 'null' }};
                    const userId = {{ $this->record->user_id }};

                    // Subscribe to support.admins channel (all admin messages)
                    window.Echo.private('support.admins')
                        .listen('.support.chat.message', (data) => {
                            :', data);
                            handleNewMessage(data);
                        });

                    // Subscribe to support.admin.{adminId} channel (messages for this admin)
                    window.Echo.private('support.admin.' + adminId)
                        .listen('.support.chat.message', (data) => {
                            :', data);
                            handleNewMessage(data);
                        });

                    // Subscribe to support.booking.{bookingId} channel if booking exists
                    if (bookingId) {
                        window.Echo.private('support.booking.' + bookingId)
                            .listen('.support.chat.message', (data) => {
                                :', data);
                                handleNewMessage(data);
                            });
                    }

                    
                }

                function initializePusherDirectly() {
                    // Get Pusher config from Laravel
                    const pusherKey = @js(config('broadcasting.connections.pusher.key'));
                    const pusherCluster = @js(config('broadcasting.connections.pusher.options.cluster', 'ap2'));

                    if (!pusherKey) {
                        console.error('Pusher key not configured. Real-time updates will not work.');
                        return;
                    }

                    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
                    if (!csrfToken) {
                        console.error('CSRF token not found. Real-time updates may not work.');
                    }

                    const pusher = new Pusher(pusherKey, {
                        cluster: pusherCluster || 'ap2',
                        encrypted: true,
                        authEndpoint: '/broadcasting/auth',
                        auth: {
                            headers: csrfToken ? {
                                'X-CSRF-TOKEN': csrfToken,
                            } : {}
                        }
                    });

                    const adminId = {{ auth()->id() }};
                    const bookingId = {{ $this->record->booking_id ?? 'null' }};

                    // Subscribe to support.admins channel
                    const adminsChannel = pusher.subscribe('private-support.admins');
                    adminsChannel.bind('pusher:subscription_succeeded', function() {
                        
                    });
                    adminsChannel.bind('pusher:subscription_error', function(error) {
                        console.error('Failed to subscribe to support.admins:', error);
                    });
                    adminsChannel.bind('support.chat.message', function(data) {
                        :', data);
                        handleNewMessage(data);
                    });

                    // Subscribe to support.admin.{adminId} channel
                    const adminChannel = pusher.subscribe('private-support.admin.' + adminId);
                    adminChannel.bind('pusher:subscription_succeeded', function() {
                        
                    });
                    adminChannel.bind('pusher:subscription_error', function(error) {
                        console.error('Failed to subscribe to support.admin.' + adminId + ':', error);
                    });
                    adminChannel.bind('support.chat.message', function(data) {
                        :', data);
                        handleNewMessage(data);
                    });

                    // Subscribe to support.booking.{bookingId} channel if booking exists
                    if (bookingId) {
                        const bookingChannel = pusher.subscribe('private-support.booking.' + bookingId);
                        bookingChannel.bind('pusher:subscription_succeeded', function() {
                            
                        });
                        bookingChannel.bind('pusher:subscription_error', function(error) {
                            console.error('Failed to subscribe to support.booking.' + bookingId + ':', error);
                        });
                        bookingChannel.bind('support.chat.message', function(data) {
                            :', data);
                            handleNewMessage(data);
                        });
                    }

                    
                }

                function handleNewMessage(data) {
                    // Check if the message belongs to this conversation
                    const currentUserId = {{ $this->record->user_id }};
                    const currentBookingId = {{ $this->record->booking_id ?? 'null' }};

                    const messageUserId = data.support_chat?.user_id;
                    const messageBookingId = data.support_chat?.booking_id;

                    // Only refresh if the message belongs to this conversation
                    if (messageUserId == currentUserId || 
                        (currentBookingId && messageBookingId == currentBookingId)) {
                        // Refresh the Livewire component to show new messages
                        try {
                            if (typeof @this !== 'undefined') {
                                @this.call('$refresh');
                            } else if (typeof Livewire !== 'undefined') {
                                Livewire.emit('$refresh');
                            } else {
                                // Fallback: reload the page
                                location.reload();
                            }
                        } catch (e) {
                            console.error('Error refreshing messages:', e);
                            // Fallback: reload the page
                            location.reload();
                        }
                        
                        // Scroll to bottom of chat messages
                        setTimeout(() => {
                            const messagesContainer = document.querySelector('[style*="overflow-y: auto"]');
                            if (messagesContainer) {
                                messagesContainer.scrollTop = messagesContainer.scrollHeight;
                            }
                        }, 100);
                    }
                }

                // Try to initialize when Echo is loaded
                if (window.Echo) {
                    initializeChatWebSocket();
                } else {
                    // Listen for EchoLoaded event
                    window.addEventListener('EchoLoaded', function() {
                        setTimeout(initializeChatWebSocket, 500);
                    });

                    // Fallback: try after a delay
                    setTimeout(function() {
                        if (window.Echo) {
                            initializeChatWebSocket();
                        } else {
                            initializeChatWebSocket();
                        }
                    }, 1000);
                }
            });
        </script>
    @endpush
</x-filament-panels::page>
