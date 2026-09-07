<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportAttachment;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DriverSupportController extends Controller
{
    public function helpCenter(): JsonResponse
    {
        $driver = Auth::user();

        if ($driver->role_id != 2) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. Only drivers can access this endpoint.',
            ], 403);
        }

        $helpCategories = [
            [
                'id' => 'ride_issues',
                'title' => 'Ride Issues',
                'description' => 'Accepting rides, tracking, Rider details, navigation problems',
                'icon' => 'car',
                'topics' => [
                    [
                        'title' => 'How to accept ride requests',
                        'description' => 'When you receive a ride request notification, tap the "Accept" button within the allocated time. Make sure you are in an active status and your location services are enabled. Once accepted, you will see the pickup location and passenger details.'
                    ],
                    [
                        'title' => "What to do when you can't find the pickup location",
                        'description' => 'If you\'re having trouble finding the pickup location, use the in-app navigation to get turn-by-turn directions. You can also contact the passenger directly through the app to confirm their exact location. If needed, use the "Call Passenger" feature to clarify any confusion about the address.'
                    ],
                    [
                        'title' => 'Handling difficult passengers',
                        'description' => 'Always maintain professionalism and remain calm. If a passenger becomes difficult or aggressive, prioritize your safety. You can report incidents through the app, contact support immediately, or in extreme cases, end the ride safely and report the issue to our support team for further action.'
                    ],
                    [
                        'title' => 'Navigation and GPS issues',
                        'description' => "If you experience GPS or navigation problems, check your phone's location permissions for the app. Ensure location services are enabled and try restarting the app. If issues persist, manually update your location in the app and consider using an external navigation app as a backup."
                    ],
                    [
                        'title' => 'Ride cancellation policies',
                        'description' => 'Drivers can cancel rides before starting the trip without major penalties, but frequent cancellations may affect your rating. Cancellations after accepting a ride may result in reduced earnings or temporary restrictions. Always try to contact the passenger before canceling to avoid misunderstandings.'
                    ]
                ]
            ],
            [
                'id' => 'payments_wallet',
                'title' => 'Payments & Wallet',
                'description' => 'Adding funds, refund process, payment issues, earnings',
                'icon' => 'wallet',
                'topics' => [
                    [
                        'title' => 'How to view your earnings',
                        'description' => 'Navigate to the "Earnings" section in your driver app to view your daily, weekly, and monthly earnings. You can see detailed breakdowns of completed rides, bonuses, and deductions. The earnings summary shows your total income, pending payments, and available balance ready for withdrawal.'
                    ],
                    [
                        'title' => 'Payment processing delays',
                        'description' => 'Payment processing typically takes 1-3 business days after a completed ride. If you experience delays beyond this timeframe, check your bank account details are correct in the app settings. Contact support with your booking numbers if payments are still pending after 5 business days for investigation.'
                    ],
                    [
                        'title' => 'Wallet balance issues',
                        'description' => 'If your wallet balance appears incorrect, refresh the app and check your transaction history. Verify all completed rides are showing correctly. If discrepancies persist, take screenshots of your earnings screen and contact support with specific ride details for resolution within 24-48 hours.'
                    ],
                    [
                        'title' => 'Refund requests',
                        'description' => 'To request a refund for incorrect charges or payment errors, go to the "Support" section and create a ticket with the relevant booking details. Include screenshots of the transaction and explain the reason for the refund. Our team reviews refund requests within 2-3 business days.'
                    ],
                    [
                        'title' => 'Payment method problems',
                        'description' => "If you're having issues with your payment method, verify your bank account or card details in the app settings. Ensure all information is accurate and up-to-date. For failed payments, check with your bank first, then update your payment method in the app and try again."
                    ]
                ]
            ],
            [
                'id' => 'promotions_offers',
                'title' => 'Promotions & Offers',
                'description' => 'How to apply promo codes, eligibility rules, bonus programs',
                'icon' => 'percentage',
                'topics' => [
                    [
                        'title' => 'Driver bonus programs',
                        'description' => 'Our driver bonus programs reward consistent performance and quality service. Bonuses are automatically calculated based on your ride completion rate, customer ratings, and number of completed rides within bonus periods. Check the "Promotions" section regularly for active bonus offers and eligibility requirements.'
                    ],
                    [
                        'title' => 'Incentive eligibility',
                        'description' => 'To be eligible for incentives, maintain a minimum rating of 4.5 stars, complete at least 90% of accepted rides, and meet the minimum ride count requirements for each incentive period. Your acceptance rate, cancellation rate, and customer feedback all factor into eligibility. Review specific incentive terms in the app.'
                    ],
                    [
                        'title' => 'Promotional campaigns',
                        'description' => "Promotional campaigns offer special rewards during peak hours, special events, or driver shortages. You'll receive notifications about active campaigns with details on bonus amounts, time frames, and location requirements. Participation is optional but can significantly boost your earnings during these periods."
                    ],
                    [
                        'title' => 'Reward point system',
                        'description' => 'Earn reward points for every completed ride, excellent ratings, and milestone achievements. Points can be redeemed for cash bonuses, fuel discounts, vehicle maintenance credits, or special merchandise. Track your points balance in the "Rewards" section and view redemption options available.'
                    ],
                    [
                        'title' => 'Special event bonuses',
                        'description' => 'During special events, holidays, or high-demand periods, we offer increased bonuses to drivers who complete rides during specified times and locations. These bonuses are in addition to your regular earnings and are automatically added to your account. Monitor app notifications for event bonus announcements.'
                    ]
                ]
            ],
            [
                'id' => 'app_troubleshooting',
                'title' => 'App Troubleshooting',
                'description' => 'Fixing crashes, location issues, and update problems',
                'icon' => 'warning',
                'topics' => [
                    [
                        'title' => 'App keeps crashing',
                        'description' => 'If the app crashes frequently, try clearing the app cache and restarting your device. Ensure you have the latest app version installed. If problems persist, uninstall and reinstall the app (make sure you remember your login credentials). Check that your device has sufficient storage space and memory available.'
                    ],
                    [
                        'title' => 'Location not updating',
                        'description' => "If your location isn't updating, check that location permissions are enabled for the app in your phone settings. Ensure GPS is turned on and you're in an area with good signal. Try closing and reopening the app, or toggling location services off and on. Restart your device if the issue continues."
                    ],
                    [
                        'title' => 'Login problems',
                        'description' => 'If you can\'t log in, verify your phone number and password are correct. Use the "Forgot Password" option to reset your password if needed. Ensure you have a stable internet connection. If account verification is required, check your SMS for the OTP code. Contact support if you continue experiencing login issues.'
                    ],
                    [
                        'title' => 'App update issues',
                        'description' => 'Regular app updates include bug fixes and new features. If update fails, check your internet connection and available storage space. Clear your app store cache or try updating from Wi-Fi. If the updated app has issues, you can temporarily roll back, but we recommend keeping the latest version for best performance.'
                    ],
                    [
                        'title' => 'Performance optimization',
                        'description' => 'To optimize app performance, regularly clear cache, close background apps, and ensure your device software is up to date. Free up storage space and keep at least 1GB available. Restart your phone daily and avoid running too many apps simultaneously. These steps help maintain smooth app operation.'
                    ]
                ]
            ]
        ];

        $raisedTickets = SupportTicket::where('user_id', $driver->id)
            ->with(['messages' => function ($query) {
                $query->public()->latest()->limit(1);
            }])
            ->latest()
            ->limit(5)
            ->get()
            ->map(function (SupportTicket $ticket) {
                $latestMessage = optional($ticket->messages->first());

                return [
                    'id' => (string) $ticket->id,
                    'ticket_number' => $ticket->ticket_number ?? '',
                    'subject' => $ticket->subject ?? '',
                    'category' => $ticket->category ?? '',
                    'priority' => $ticket->priority ?? '',
                    'status' => $ticket->status ?? '',
                    'created_at' => $ticket->created_at?->toISOString() ?? '',
                    'updated_at' => $ticket->updated_at?->toISOString() ?? '',
                    'resolved_at' => $ticket->resolved_at?->toISOString() ?? '',
                    'closed_at' => $ticket->closed_at?->toISOString() ?? '',
                    'last_message' => $latestMessage->message ?? '',
                    'last_message_at' => $latestMessage->created_at?->toISOString() ?? '',
                    'message_count' => (string) $ticket->messages_count ?? '0',
                    'attachment_count' => (string) $ticket->attachments_count ?? '0',
                ];
            });

        return response()->json([
            'success' => true,
            'message' => 'Help center data retrieved successfully',
            'data' => [
                'categories' => $helpCategories,
                'raised_tickets' => [
                    'tickets' => $raisedTickets,
                    'total_count' => $raisedTickets->count(),
                    'has_more' => SupportTicket::where('user_id', $driver->id)->count() > 5
                ],
                'virtual_assistant' => [
                    'greeting' => "Hi there! I'm your virtual assistant",
                    'description' => 'I can help you with ride issues, payments, account settings, safety concerns, and more. Type your question or choose from the options below.',
                    'quick_actions' => [
                        ['id' => 'ride_issues', 'title' => 'Ride Issues', 'icon' => 'car'],
                        ['id' => 'payments_wallet', 'title' => 'Payments & Wallet', 'icon' => 'wallet'],
                        ['id' => 'promotions_offers', 'title' => 'Offers & Rewards', 'icon' => 'percentage'],
                        ['id' => 'app_troubleshooting', 'title' => 'App Related Issues', 'icon' => 'warning']
                    ]
                ],
                'contact_support' => [
                    'available' => true,
                    'response_time' => 'Usually within 2 hours',
                    'methods' => ['in_app', 'email', 'phone']
                ]
            ]
        ]);
    }

    public function getTickets(Request $request): JsonResponse
    {
        $driver = Auth::user();

        if ($driver->role_id != 2) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. Only drivers can access this endpoint.',
            ], 403);
        }

        $tickets = SupportTicket::where('user_id', $driver->id)
            ->with(['messages' => function ($query) {
                $query->public()->latest()->limit(1);
            }, 'booking', 'attachments'])
            ->latest()
            ->paginate($request->input('per_page', 10));

        $collection = $tickets->getCollection()->map(function (SupportTicket $ticket) {
            return $this->serializeTicketForList($ticket);
        });
        $tickets->setCollection($collection);

        return response()->json([
            'success' => true,
            'message' => 'Support tickets retrieved successfully',
            'data' => $tickets
        ]);
    }

    public function getTicketDetails($ticketId): JsonResponse
    {
        $driver = Auth::user();

        if ($driver->role_id != 2) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. Only drivers can access this endpoint.',
            ], 403);
        }

        $ticket = SupportTicket::where('user_id', $driver->id)
            ->with(['messages.user', 'attachments', 'booking'])
            ->findOrFail($ticketId);

        $ticketData = [
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'subject' => $ticket->subject,
            'category' => $ticket->category,
            'priority' => $ticket->priority,
            'status' => $ticket->status,
            'created_at' => $ticket->created_at,
            'updated_at' => $ticket->updated_at,
            'resolved_at' => $ticket->resolved_at ?? '',
            'closed_at' => $ticket->closed_at ?? '',
            'booking' => $ticket->booking ? [
                'id' => $ticket->booking->id,
                'booking_number' => $ticket->booking->booking_number ?? '',
                'trip_id' => $ticket->booking->booking_code ?? '',
                'pickup_location' => $ticket->booking->pickup_location,
                'dropoff_location' => $ticket->booking->dropoff_location,
                'created_at' => $ticket->booking->created_at,
            ] : null,
            'messages' => $ticket->messages->map(function ($message) {
                return [
                    'id' => $message->id,
                    'message' => $message->message,
                    'is_from_support' => $message->is_from_support ?? '',
                    'created_at' => $message->created_at,
                    'user' => $message->user ? [
                        'id' => $message->user->id,
                        'name' => $message->user->name,
                        'role' => $message->user->role_id == 1 ? 'user' : ($message->user->role_id == 2 ? 'driver' : 'admin')
                    ] : null
                ];
            }),
            'attachments' => $ticket->attachments->map(function ($attachment) use ($ticket) {
                $mimeType = $attachment->mime_type ?? '';
                $filename = $attachment->filename ?? $attachment->original_name ?? '';
                $isImage = str_starts_with($mimeType, 'image/') ||
                    preg_match('/\.(jpg|jpeg|png|gif|bmp|webp|svg)$/i', $filename);

                $imageUrl = $isImage ? $this->getStorageUrl($attachment->file_path) : '';

                return [
                    'id' => $attachment->id,
                    'filename' => $attachment->filename ?? '',
                    'original_name' => $attachment->original_name ?? '',
                    'file_size' => $attachment->file_size ?? '',
                    'mime_type' => $mimeType,
                    'image_url' => $this->getStorageUrl($attachment->file_path),  // Full URL instead of relative path
                    'download_url' => route('support.tickets.attachments.download', [
                        'ticketId' => $ticket->id,
                        'attachmentId' => $attachment->id
                    ])
                ];
            })
        ];

        return response()->json([
            'success' => true,
            'message' => 'Ticket details retrieved successfully',
            'data' => $ticketData
        ]);
    }

    public function createTicket(Request $request): JsonResponse
    {
        $driver = Auth::user();
        if ($driver->role_id != 2) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. Only drivers can access this endpoint.',
            ], 403);
        }

        $data = $request->validate([
            'booking_id' => ['nullable', 'integer', 'exists:bookings,id'],
            'category' => ['nullable', 'string', 'max:255'],
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['nullable', 'file', 'max:10240'],  // 10MB max
        ]);

        try {
            DB::beginTransaction();

            $category = $data['category'] ?? null;
            $priority = ($category === SupportTicket::CATEGORY_PAYMENT)
                ? SupportTicket::PRIORITY_HIGH
                : SupportTicket::PRIORITY_MEDIUM;

            $ticketData = [
                'user_id' => $driver->id,
                'category' => $category,
                'subject' => $data['subject'] ?? null,
                'priority' => $priority,
            ];

            if (!empty($data['booking_id'])) {
                $bookingOwnedByDriver = \App\Models\Booking::whereKey((int) $data['booking_id'])
                    ->where('driver_id', $driver->id)
                    ->exists();

                if (!$bookingOwnedByDriver) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthorized booking reference',
                    ], 403);
                }
                $ticketData['booking_id'] = (int) $data['booking_id'];
            }

            $ticket = SupportTicket::create($ticketData);

            if (!empty($data['message'])) {
                $message = $ticket->messages()->create([
                    'user_id' => $driver->id,
                    'message' => $data['message'],
                ]);
            }

            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $filename = time() . '_' . $file->getClientOriginalName();
                    $path = $file->storeAs('support/attachments', $filename, 'public');

                    $ticket->attachments()->create([
                        'filename' => $filename,
                        'original_name' => $file->getClientOriginalName() ?? '',
                        'file_path' => $path,
                        'file_size' => $file->getSize(),
                        'mime_type' => $file->getMimeType(),
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Support ticket created successfully',
                'data' => [
                    'ticket' => [
                        'id' => $ticket->id,
                        'ticket_number' => $ticket->ticket_number,
                        'subject' => $ticket->subject,
                        'category' => $ticket->category,
                        'priority' => $ticket->priority,
                        'status' => $ticket->status,
                        'created_at' => $ticket->created_at,
                    ]
                ]
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollback();


            return response()->json([
                'success' => false,
                'message' => 'Failed to create support ticket',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 500);
        }
    }

    public function replyToTicket(Request $request, $ticketId): JsonResponse
    {
        $driver = Auth::user();

        if ($driver->role_id != 2) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. Only drivers can access this endpoint.',
            ], 403);
        }

        $ticket = SupportTicket::where('user_id', $driver->id)->findOrFail($ticketId);

        $data = $request->validate([
            'message' => ['required', 'string'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['required', 'file', 'max:10240'],
        ]);

        try {
            DB::beginTransaction();

            $message = $ticket->messages()->create([
                'user_id' => $driver->id,
                'message' => $data['message'],
            ]);

            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $filename = time() . '_' . $file->getClientOriginalName();
                    $path = $file->storeAs('support/attachments', $filename, 'public');

                    $ticket->attachments()->create([
                        'filename' => $filename,
                        'original_name' => $file->getClientOriginalName(),
                        'file_path' => $path,
                        'file_size' => $file->getSize(),
                        'mime_type' => $file->getMimeType(),
                    ]);
                }
            }

            if ($ticket->status === SupportTicket::STATUS_CLOSED) {
                $ticket->update(['status' => SupportTicket::STATUS_OPEN]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Reply sent successfully',
                'data' => [
                    'message' => [
                        'id' => $message->id,
                        'message' => $message->message,
                        'created_at' => $message->created_at,
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollback();

            return response()->json([
                'success' => false,
                'message' => 'Failed to send reply',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function downloadAttachment($ticketId, $attachmentId): JsonResponse
    {
        $driver = Auth::user();

        if ($driver->role_id != 2) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. Only drivers can access this endpoint.',
            ], 403);
        }

        $ticket = SupportTicket::where('user_id', $driver->id)->findOrFail($ticketId);
        $attachment = SupportAttachment::where('ticket_id', $ticket->id)->findOrFail($attachmentId);

        if (!Storage::exists($attachment->file_path)) {
            return response()->json([
                'success' => false,
                'message' => 'The requested file does not exist.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Download URL generated successfully',
            'data' => [
                'url' => Storage::temporaryUrl(
                    $attachment->file_path,
                    now()->addMinutes(5),
                    [
                        'ResponseContentType' => $attachment->mime_type ?? $attachment->file_type ?? 'application/octet-stream',
                        'ResponseContentDisposition' => 'attachment; filename="' . ($attachment->original_name ?? $attachment->name ?? '') . '"',
                    ]
                ),
                'filename' => $attachment->original_name ?? $attachment->name ?? '',
                'mime_type' => $attachment->mime_type ?? $attachment->file_type ?? '',
                'file_size' => $attachment->file_size ?? '',
            ]
        ]);
    }

    public function updateAttachment(Request $request, $ticketId, $attachmentId): JsonResponse
    {
        $driver = Auth::user();

        if ($driver->role_id != 2) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. Only drivers can access this endpoint.',
            ], 403);
        }

        $ticket = SupportTicket::where('user_id', $driver->id)->findOrFail($ticketId);
        $attachment = SupportAttachment::where('ticket_id', $ticket->id)
            ->where('user_id', $driver->id)
            ->findOrFail($attachmentId);

        if ((int) $attachment->user_id !== $driver->id) {
            return response()->json([
                'success' => false,
                'message' => 'You can only update your own attachments.',
            ], 403);
        }

        $data = $request->validate([
            'file' => ['nullable', 'file', 'max:10240'],  // 10MB max
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            DB::beginTransaction();

            $updateData = [];

            if ($request->hasFile('file')) {
                $file = $request->file('file');

                if (Storage::exists($attachment->file_path)) {
                    Storage::delete($attachment->file_path);
                }

                $filename = time() . '_' . $file->getClientOriginalName();
                $path = $file->storeAs('support/attachments', $filename, 'public');

                $updateData = [
                    'file_name' => $filename,
                    'name' => $data['name'] ?? $file->getClientOriginalName(),
                    'file_path' => $path,
                    'file_size' => $file->getSize(),
                    'file_type' => $file->getMimeType(),
                ];
            } elseif (isset($data['name'])) {
                $updateData['name'] = $data['name'];
            }

            if (!empty($updateData)) {
                $attachment->update($updateData);
                $attachment->refresh();
            }

            DB::commit();

            $attachment = $attachment->fresh();

            return response()->json([
                'success' => true,
                'message' => 'Attachment updated successfully',
                'data' => [
                    'attachment' => [
                        'id' => $attachment->id,
                        'filename' => $attachment->filename ?? '',
                        'original_name' => $attachment->original_name ?? '',
                        'file_size' => $attachment->file_size ?? '',
                        'mime_type' => $attachment->mime_type ?? '',
                        'image_url' => $this->getStorageUrl($attachment->file_path),
                        'download_url' => route('support.tickets.attachments.download', [
                            'ticketId' => $ticket->id,
                            'attachmentId' => $attachment->id
                        ])
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollback();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update attachment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function deleteAttachment($ticketId, $attachmentId): JsonResponse
    {
        $driver = Auth::user();

        if ($driver->role_id != 2) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. Only drivers can access this endpoint.',
            ], 403);
        }

        $ticket = SupportTicket::where('user_id', $driver->id)->findOrFail($ticketId);
        $attachment = SupportAttachment::where('ticket_id', $ticket->id)
            ->where('user_id', $driver->id)
            ->findOrFail($attachmentId);

        if ((int) $attachment->user_id !== $driver->id) {
            return response()->json([
                'success' => false,
                'message' => 'You can only delete your own attachments.',
            ], 403);
        }

        try {
            DB::beginTransaction();

            if (Storage::exists($attachment->file_path)) {
                Storage::delete($attachment->file_path);
            }

            $attachment->forceDelete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Attachment deleted successfully',
            ]);
        } catch (\Exception $e) {
            DB::rollback();

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete attachment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function serializeTicketForList(SupportTicket $ticket): array
    {
        $latestMessage = optional($ticket->messages->first());

        $payload = [
            'id' => (string) $ticket->id,
            'ticket_number' => $ticket->ticket_number ?? '',
            'subject' => $ticket->subject ?? '',
            'category' => $ticket->category ?? '',
            'priority' => $ticket->priority ?? '',
            'status' => $ticket->status ?? '',
            'created_at' => $ticket->created_at?->toISOString() ?? '',
            'updated_at' => $ticket->updated_at?->toISOString() ?? '',
            'resolved_at' => $ticket->resolved_at?->toISOString() ?? '',
            'closed_at' => $ticket->closed_at?->toISOString() ?? '',
            'last_message' => $latestMessage->message ?? '',
            'last_message_at' => $latestMessage->created_at?->toISOString() ?? '',
            'message_count' => (string) $ticket->messages_count ?? '0',
            'attachment_count' => (string) $ticket->attachments_count ?? '0',
            'booking' => $ticket->booking ? [
                'id' => $ticket->booking->id,
                'booking_number' => $ticket->booking->booking_number ?? '',
                'booking_code' => $ticket->booking->booking_code ?? '',
                'trip_id' => $ticket->booking->booking_code ?? '',
                'pickup_location' => $ticket->booking->pickup_location,
                'dropoff_location' => $ticket->booking->dropoff_location,
                'created_at' => $ticket->booking->created_at,
            ] : null,
            'attachments' => $ticket->attachments->map(function ($attachment) use ($ticket) {
                $mimeType = $attachment->mime_type ?? '';
                $filename = $attachment->filename ?? $attachment->original_name ?? '';
                $isImage = str_starts_with($mimeType, 'image/') ||
                    preg_match('/\.(jpg|jpeg|png|gif|bmp|webp|svg)$/i', $filename);

                $imageUrl = $isImage ? $this->getStorageUrl($attachment->file_path) : '';

                return [
                    'id' => $attachment->id,
                    'filename' => $attachment->filename ?? '',
                    'original_name' => $attachment->original_name ?? '',
                    'file_size' => $attachment->file_size ?? '',
                    'mime_type' => $mimeType,
                    'is_image' => $isImage,
                    'image_url' => $imageUrl,
                    'file_path' => $this->getStorageUrl($attachment->file_path),  // Full URL instead of relative path
                    'download_url' => route('support.tickets.attachments.download', [
                        'ticketId' => $ticket->id,
                        'attachmentId' => $attachment->id
                    ])
                ];
            }),
        ];

        return $payload;
    }

    private function getStorageUrl($filePath): string
    {
        if (empty($filePath)) {
            return '';
        }

        $cleanPath = str_replace('storage/app/public/', '', $filePath);
        $cleanPath = ltrim($cleanPath, '/');

        $url = url('storage/' . $cleanPath);

        if (empty($url) || $url === 'storage/' . $cleanPath) {
            $url = request()->getSchemeAndHttpHost() . '/storage/' . $cleanPath;
        }

        return $url;
    }
}
