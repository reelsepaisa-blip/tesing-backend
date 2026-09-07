<?php

use App\Http\Controllers\Api\Admin\SupportTicketController as AdminSupportTicketController;
use App\Http\Controllers\Api\Auth\{UserAuthController, DriverAuthController};
use App\Http\Controllers\Api\{ChatController, DriverBookingController, SupportTicketController, PromoController, CityController, TripController, DriverTrackingController, WebSocketController, LocationController, PaymentController, DriverTripController, DriverPaymentController, CustomerOfferController, PaymentMethodController, DriverAttendanceController, DriverIncentiveController, WebhookController, BulkOperationsController, DriverLocationWebSocketController, DatabaseManagerAuthController, DriverTokenController, WalletController, NotificationController, BannerController, EmergencyContactController, DriverTripActivityController, IssueReportController, DriverEmergencyContactController, DriverSupportController, DriverAccountController, ContentController, BookingContactController, CashCollectionPointController, SettingsController};
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\DriverLocationController;
use App\Http\Controllers\Api\DriverPerformanceController;
use Illuminate\Support\Facades\Route;

Route::prefix('ride-types')->group(function () {
    Route::get('/', [App\Http\Controllers\Api\RideTypeController::class, 'index']);
    Route::get('{id}', [App\Http\Controllers\Api\RideTypeController::class, 'show']);
});

Route::prefix('cities')->group(function () {
    Route::get('/', [CityController::class, 'index']);
    Route::post('nearest', [CityController::class, 'nearest']);
    Route::post('check-serviceability', [CityController::class, 'checkServiceability']);
    Route::post('available-ride-types', [CityController::class, 'availableRideTypes']);
    Route::post('nearby-drivers', [CityController::class, 'findNearbyDrivers']);
});

Route::prefix('banners')->group(function () {
    Route::get('/', [BannerController::class, 'index']);
    Route::get('/row/{row}', [BannerController::class, 'getByRow']);
    Route::get('/{id}', [BannerController::class, 'show']);
});

Route::prefix('content')->group(function () {
    Route::get('/about-us', [ContentController::class, 'getAboutUs']);
    Route::get('/terms-conditions', [ContentController::class, 'getTermsConditions']);
    Route::get('/privacy-policy', [ContentController::class, 'getPrivacyPolicy']);
    Route::get('/contact-us', [ContentController::class, 'getContactUs']);
});

Route::prefix('cash-collection-points')->group(function () {
    Route::get('/', [CashCollectionPointController::class, 'index']);
});

Route::prefix('settings')->group(function () {
    Route::get('/', [SettingsController::class, 'getSettings']);
});

Route::prefix('user')->group(function () {
    Route::post('check-email', [UserAuthController::class, 'checkEmail']);  // Check if email exists
    Route::post('send-otp', [UserAuthController::class, 'sendOTPForLogin']);
    Route::post('login/otp', [UserAuthController::class, 'loginWithOTP']);
    Route::post('otp_login', [UserAuthController::class, 'otpLogin']);  // Firebase/Client-side verified login
    Route::post('login/password', [UserAuthController::class, 'loginWithPassword']);  // Unified login endpoint
    Route::post('register', [UserAuthController::class, 'register']);
    Route::post('forgot-password', [UserAuthController::class, 'forgotPassword']);
    Route::post('reset-password', [UserAuthController::class, 'resetPassword']);

    Route::middleware(\App\Http\Middleware\BearerTokenAuth::class)->group(function () {
        Route::post('logout', [UserAuthController::class, 'logout']);
        Route::post('update-profile', [UserAuthController::class, 'updateProfile']);
        Route::get('profile', [UserAuthController::class, 'getUserProfile']);
        Route::post('change-password', [UserAuthController::class, 'changePassword']);
        Route::post('refresh-token', [UserAuthController::class, 'refreshToken']);
        Route::get('token-info', [UserAuthController::class, 'getTokenInfo']);

        Route::post('delete-account', [UserAuthController::class, 'deleteAccount']);
        Route::post('deactivate-account', [UserAuthController::class, 'deactivateAccount']);

        Route::post('complete-step', [UserAuthController::class, 'completeStep']);
        Route::get('step-status', [UserAuthController::class, 'getStepStatus']);
        Route::post('reset-steps', [UserAuthController::class, 'resetSteps']);
    });
});

Route::prefix('driver')->group(function () {
    Route::post('send-otp', [DriverAuthController::class, 'sendOTPForLogin']);
    Route::post('login/otp', [DriverAuthController::class, 'loginWithOTP']);
    Route::post('login/password', [DriverAuthController::class, 'loginWithPassword']);
    Route::post('register', [DriverAuthController::class, 'register']);  // Multi-step registration (handles auth internally)
    Route::get('required-documents', [DriverAuthController::class, 'getRequiredDocuments']);
    Route::get('vehicle-documents', [DriverAuthController::class, 'getVehicleDocuments']);

    Route::middleware(\App\Http\Middleware\BearerTokenAuth::class)->group(function () {
        Route::post('logout', [DriverAuthController::class, 'logout']);
        Route::post('refresh-token', [DriverAuthController::class, 'refreshToken']);
        Route::get('profile', [DriverAuthController::class, 'getDriverProfile']);
        Route::post('document', [DriverAuthController::class, 'uploadDocument']);
        Route::get('documents', [DriverAuthController::class, 'getDriverDocuments']);
        Route::post('toggle-online', [DriverAuthController::class, 'toggleOnlineStatus']);
        Route::post('update-registration-number', [DriverAuthController::class, 'updateRegistrationNumber']);

        Route::get('token-info', [DriverTokenController::class, 'getDriverInfo']);
        Route::get('extract-driver-id', [DriverTokenController::class, 'extractDriverId']);

        Route::prefix('trip-activity')->group(function () {
            Route::get('/', [DriverTripActivityController::class, 'getTripActivity']);
            Route::get('summary', [DriverTripActivityController::class, 'getSummary']);
            Route::get('{bookingId}', [DriverTripActivityController::class, 'getTripDetails']);
        });

        Route::prefix('emergency-contacts')->group(function () {
            Route::get('/', [DriverEmergencyContactController::class, 'index']);
            Route::post('/', [DriverEmergencyContactController::class, 'store']);
            Route::put('{id}', [DriverEmergencyContactController::class, 'update']);
            Route::delete('{id}', [DriverEmergencyContactController::class, 'destroy']);
        });

        Route::prefix('support')->group(function () {
            Route::get('help-center', [DriverSupportController::class, 'helpCenter']);
            Route::get('tickets', [DriverSupportController::class, 'getTickets']);
            Route::get('tickets/{ticketId}', [DriverSupportController::class, 'getTicketDetails']);
            Route::post('tickets', [DriverSupportController::class, 'createTicket']);
            Route::post('tickets/{ticketId}/reply', [DriverSupportController::class, 'replyToTicket']);
            Route::get('tickets/{ticketId}/attachments/{attachmentId}/download', [DriverSupportController::class, 'downloadAttachment'])->name('support.tickets.attachments.download');
            Route::post('tickets/{ticketId}/attachments/{attachmentId}', [DriverSupportController::class, 'updateAttachment']);
            Route::delete('tickets/{ticketId}/attachments/{attachmentId}', [DriverSupportController::class, 'deleteAttachment']);
        });

        Route::post('delete-account', [DriverAccountController::class, 'deleteAccount']);
        Route::post('deactivate-account', [DriverAccountController::class, 'deactivateAccount']);

        Route::prefix('saved-locations')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\DriverSavedLocationController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\DriverSavedLocationController::class, 'store']);
            Route::put('{id}', [\App\Http\Controllers\Api\DriverSavedLocationController::class, 'update']);
            Route::delete('{id}', [\App\Http\Controllers\Api\DriverSavedLocationController::class, 'destroy']);
            Route::get('type/{type}', [\App\Http\Controllers\Api\DriverSavedLocationController::class, 'getByType']);
            Route::post('{id}/set-default', [\App\Http\Controllers\Api\DriverSavedLocationController::class, 'setDefault']);
        });

        Route::prefix('performance')->group(function () {
            Route::get('/', [DriverPerformanceController::class, 'getPerformanceOverview']);
            Route::get('details', [DriverPerformanceController::class, 'getPerformanceDetails']);
            Route::get('reviews', [DriverPerformanceController::class, 'getAllReviews']);
        });
    });
});

Route::middleware(\App\Http\Middleware\BearerTokenAuth::class)->group(function () {
    Route::prefix('bookings')->group(function () {
        Route::post('estimate', [BookingController::class, 'estimate']);
        Route::post('create', [BookingController::class, 'create']);
        Route::post('select-ride-type', [BookingController::class, 'selectRideType']);
        Route::post('{booking}/update-details', [BookingController::class, 'updateBookingDetails']);
        Route::post('{booking}/update-pickup', [BookingController::class, 'updatePickup']);
        Route::post('update-payment-method', [BookingController::class, 'updatePaymentMethod']);  // Update payment method API
        Route::post('{booking}/cancel', [BookingController::class, 'cancel']);
        Route::post('cancel', [BookingController::class, 'cancel']);  // New cancel API with booking_id in request body
        Route::post('{booking}/is-conform', [BookingController::class, 'isConform']);
        Route::get('current', [BookingController::class, 'getCurrentBooking']);
        Route::get('history', [BookingController::class, 'getBookingHistory']);
        Route::post('{booking}/rate', [BookingController::class, 'rateDriver']);
        Route::post('review-driver', [BookingController::class, 'reviewDriver']);
    });
});

Route::middleware(\App\Http\Middleware\BearerTokenAuth::class)->group(function () {
    Route::prefix('trips')->group(function () {
        Route::get('current', [TripController::class, 'getCurrentTrip']);
        Route::post('{booking}/start', [TripController::class, 'startTrip']);
        Route::post('{booking}/complete', [TripController::class, 'completeTrip']);
        Route::post('{booking}/location', [TripController::class, 'updateLocation']);
        Route::post('{booking}/rate-driver', [TripController::class, 'rateDriver']);
        Route::post('{booking}/rate-user', [TripController::class, 'rateUser']);
        Route::get('history', [TripController::class, 'getTripHistory']);
        Route::get('trip-info', [TripController::class, 'getTripInfo']);
        Route::get('{booking}/invoice', [TripController::class, 'downloadInvoice']);
    });
});

Route::middleware([\App\Http\Middleware\BearerTokenAuth::class, \App\Http\Middleware\EnsureAdminApiAccess::class])->group(function () {
    Route::post('driver/bookings/auto-accept', [DriverBookingController::class, 'autoAccept']);
});

Route::middleware(\App\Http\Middleware\BearerTokenAuth::class)->group(function () {
    Route::prefix('driver/bookings')->group(function () {
        Route::post('{booking}/update-status', [DriverBookingController::class, 'updateStatus']);

        Route::post('{booking}/accept', [DriverBookingController::class, 'accept']);
        Route::post('{booking}/arrived', [DriverBookingController::class, 'arrived']);
        Route::post('{booking}/start', [DriverBookingController::class, 'start']);
        Route::post('{booking}/complete', [DriverBookingController::class, 'complete']);
        Route::post('{booking}/cancel', [DriverBookingController::class, 'cancel']);
        Route::post('{booking}/location', [DriverBookingController::class, 'updateLocation']);
        Route::get('current', [DriverBookingController::class, 'getCurrentBooking']);
        Route::get('history', [DriverBookingController::class, 'getBookingHistory']);
        Route::post('{booking}/rate', [DriverBookingController::class, 'rateUser']);
        Route::post('review-customer', [DriverBookingController::class, 'reviewCustomer']);

        Route::post('match-otp', [DriverBookingController::class, 'matchOtp']);

        Route::post('collect-cash', [DriverBookingController::class, 'collectCash']);

        Route::post('broadcast-to-all', [DriverBookingController::class, 'broadcastToDriverAll']);
    });
});

Route::middleware(\App\Http\Middleware\BearerTokenAuth::class)->group(function () {
    Route::prefix('issues')->group(function () {
        Route::post('report', [IssueReportController::class, 'reportIssue']);
        Route::get('types', [IssueReportController::class, 'getIssueTypes']);
        Route::get('booking/{bookingId}', [IssueReportController::class, 'getBookingIssues']);
    });
});

Route::middleware(\App\Http\Middleware\BearerTokenAuth::class)->group(function () {
    Route::prefix('cancellation-fee-disputes')->group(function () {
        Route::get('reasons', [\App\Http\Controllers\Api\CancellationFeeDisputeController::class, 'getDisputeReasons']);
        Route::post('submit', [\App\Http\Controllers\Api\CancellationFeeDisputeController::class, 'submitDispute']);
        Route::get('history', [\App\Http\Controllers\Api\CancellationFeeDisputeController::class, 'getDisputeHistory']);
        Route::get('{disputeId}', [\App\Http\Controllers\Api\CancellationFeeDisputeController::class, 'getDisputeDetails']);
        Route::post('check-eligibility', [\App\Http\Controllers\Api\CancellationFeeDisputeController::class, 'checkDisputeEligibility']);
    });
});

Route::middleware(\App\Http\Middleware\BearerTokenAuth::class)->group(function () {
    Route::prefix('support')->group(function () {
        Route::get('messages_list', [SupportTicketController::class, 'messagesByBooking']);
        Route::get('tickets', [SupportTicketController::class, 'index']);
        Route::post('tickets', [SupportTicketController::class, 'store']);
        Route::get('tickets/{ticket}', [SupportTicketController::class, 'show']);
        Route::get('tickets/{ticket}/messages', [SupportTicketController::class, 'messages']);
        Route::post('tickets/{ticket}/reply', [SupportTicketController::class, 'reply']);
        Route::post('tickets/{ticket}/close', [SupportTicketController::class, 'close']);
        Route::post('tickets/{ticket}/reopen', [SupportTicketController::class, 'reopen']);
        Route::get('tickets/{ticket}/attachments/{attachment}/download', [SupportTicketController::class, 'downloadAttachment']);
    });
});

Route::middleware(\App\Http\Middleware\BearerTokenAuth::class)->group(function () {
    Route::prefix('refunds')->group(function () {
        Route::get('reasons', [\App\Http\Controllers\Api\RefundController::class, 'getRefundReasons']);
        Route::post('request', [\App\Http\Controllers\Api\RefundController::class, 'submitRefundRequest']);
        Route::get('my-requests', [\App\Http\Controllers\Api\RefundController::class, 'getMyRefundRequests']);
        Route::get('{id}', [\App\Http\Controllers\Api\RefundController::class, 'getRefundRequestDetails']);
    });
});

Route::middleware([\App\Http\Middleware\BearerTokenAuth::class, 'role:admin'])->group(function () {
    Route::prefix('admin/refunds')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\RefundController::class, 'getAllRefundRequests']);
        Route::get('{id}', [\App\Http\Controllers\Api\RefundController::class, 'getAdminRefundRequestDetails']);
        Route::post('{id}/approve', [\App\Http\Controllers\Api\RefundController::class, 'approveRefundRequest']);
        Route::post('{id}/reject', [\App\Http\Controllers\Api\RefundController::class, 'rejectRefundRequest']);
    });
});

Route::middleware([\App\Http\Middleware\BearerTokenAuth::class, 'role:admin'])->group(function () {
    Route::prefix('admin/support')->group(function () {
        Route::get('tickets', [AdminSupportTicketController::class, 'index']);
        Route::get('tickets/{ticket}', [AdminSupportTicketController::class, 'show']);
        Route::patch('tickets/{ticket}', [AdminSupportTicketController::class, 'update']);
        Route::post('tickets/{ticket}/reply', [AdminSupportTicketController::class, 'reply']);
        Route::post('tickets/{ticket}/resolve', [AdminSupportTicketController::class, 'resolve']);
        Route::post('tickets/{ticket}/close', [AdminSupportTicketController::class, 'close']);
        Route::post('tickets/{ticket}/reopen', [AdminSupportTicketController::class, 'reopen']);
        Route::post('tickets/bulk-assign', [AdminSupportTicketController::class, 'bulkAssign']);
        Route::post('tickets/bulk-close', [AdminSupportTicketController::class, 'bulkClose']);
        Route::get('tickets/stats', [AdminSupportTicketController::class, 'stats']);
    });
});

Route::middleware(\App\Http\Middleware\BearerTokenAuth::class)->group(function () {
    Route::prefix('promos')->group(function () {
        Route::get('validate/{code}', [PromoController::class, 'validate']);
        Route::post('apply', [PromoController::class, 'apply']);
        Route::get('my-codes', [PromoController::class, 'myCodes']);
        Route::get('referral-stats', [PromoController::class, 'referralStats']);
    });
});
Route::middleware([\App\Http\Middleware\BearerTokenAuth::class, 'role:admin'])->group(function () {
    Route::get('tickets/timeline', [AdminSupportTicketController::class, 'timelineByBooking']);
});
Route::middleware(['web', \App\Http\Middleware\EnsureAdminApiAccess::class])->group(function () {
    Route::get('/driver-tracking/all', [DriverTrackingController::class, 'getAllDrivers']);
});
Route::middleware(\App\Http\Middleware\BearerTokenAuth::class)->group(function () {
    Route::post('/driver-tracking/update-location', [DriverTrackingController::class, 'updateLocation']);
    Route::post('/driver-tracking/update-online-status', [DriverTrackingController::class, 'updateOnlineStatus']);
});

Route::middleware(\App\Http\Middleware\BearerTokenAuth::class)->group(function () {
    Route::post('/driver/location', [DriverLocationController::class, 'updateLocation']);
    Route::post('/driver/location/update', [DriverLocationController::class, 'updateLocation']);
    Route::get('/driver/location/current', [DriverLocationController::class, 'getCurrentLocation']);
    Route::get('/driver/location/history', [DriverLocationController::class, 'getLocationHistory']);
    Route::post('/driver/location/bulk-update', [DriverLocationController::class, 'bulkUpdateLocation']);

    Route::post('/driver/location/websocket-update', [DriverLocationWebSocketController::class, 'updateLocationWithWebSocket']);
    Route::post('/driver/location/start-auto-updates', [DriverLocationWebSocketController::class, 'startAutoLocationUpdates']);
    Route::post('/driver/location/stop-auto-updates', [DriverLocationWebSocketController::class, 'stopAutoLocationUpdates']);
    Route::get('/driver/location/auto-status', [DriverLocationWebSocketController::class, 'getAutoLocationStatus']);
});

Route::middleware(\App\Http\Middleware\BearerTokenAuth::class)->group(function () {
    Route::prefix('websocket')->group(function () {
        Route::get('channels', [WebSocketController::class, 'getChannels']);
        Route::get('connection-info', [WebSocketController::class, 'getConnectionInfo']);
        Route::get('connection-status', [WebSocketController::class, 'getConnectionStatus']);
        Route::post('update-driver-location', [WebSocketController::class, 'updateDriverLocation']);
        Route::post('update-trip-status', [WebSocketController::class, 'updateTripStatus']);
        Route::get('trip-info', [WebSocketController::class, 'getTripInfo']);
        Route::get('nearby-drivers', [WebSocketController::class, 'getNearbyDrivers']);
        Route::post('update-fcm-token', [WebSocketController::class, 'updateFCMToken']);
        Route::get('active-trips', [WebSocketController::class, 'getActiveTrips']);

        Route::post('start-auto-location', [WebSocketController::class, 'startAutoLocationUpdates']);
        Route::post('stop-auto-location', [WebSocketController::class, 'stopAutoLocationUpdates']);
        Route::get('auto-location-status', [WebSocketController::class, 'getAutoLocationStatus']);
        Route::post('store-realtime-location', [WebSocketController::class, 'storeRealtimeLocation']);

        Route::post('websocket-location-update', [\App\Http\Controllers\Api\WebSocketLocationController::class, 'handleLocationUpdate']);
        Route::post('websocket-authenticate', [\App\Http\Controllers\Api\WebSocketLocationController::class, 'authenticateWebSocket']);

        Route::post('driver-location-by-booking', [\App\Http\Controllers\Api\DriverLocationByBookingController::class, 'handleLocationUpdate']);
    });

    Route::get('trips/{booking}/channel', [WebSocketController::class, 'getTripChannel']);

    Route::prefix('websocket-chat')->group(function () {
        Route::post('send-message', [\App\Http\Controllers\Api\WebSocketChatController::class, 'sendMessage']);
        Route::get('messages/{booking}', [\App\Http\Controllers\Api\WebSocketChatController::class, 'getMessages']);
        Route::post('mark-read', [\App\Http\Controllers\Api\WebSocketChatController::class, 'markAsRead']);
        Route::post('typing', [\App\Http\Controllers\Api\WebSocketChatController::class, 'setTypingStatus']);
        Route::get('unread-count/{booking}', [\App\Http\Controllers\Api\WebSocketChatController::class, 'getUnreadCount']);
        Route::post('send-image', [\App\Http\Controllers\Api\WebSocketChatController::class, 'sendImage']);
        Route::post('send-location', [\App\Http\Controllers\Api\WebSocketChatController::class, 'sendLocation']);
        Route::get('connection-info', [\App\Http\Controllers\Api\WebSocketChatController::class, 'getConnectionInfo']);
    });

    Route::prefix('websocket-direct')->group(function () {
        Route::post('handle-message', [\App\Http\Controllers\Api\WebSocketDirectChatController::class, 'handleDirectMessage']);
        Route::post('authenticate', [\App\Http\Controllers\Api\WebSocketDirectChatController::class, 'authenticateWebSocket']);
    });
});

Route::middleware(\App\Http\Middleware\BearerTokenAuth::class)->post('/pusher/auth', [\App\Http\Controllers\Api\WebSocketDirectChatController::class, 'authenticateWebSocket']);

Route::middleware(\App\Http\Middleware\BearerTokenAuth::class)->post('/support-websocket/auth', [\App\Http\Controllers\Api\SupportChatWebSocketController::class, 'authenticateWebSocket']);
Route::middleware(\App\Http\Middleware\BearerTokenAuth::class)->post('/support-websocket/message', [\App\Http\Controllers\Api\SupportChatWebSocketController::class, 'handleDirectMessage']);

Route::middleware(\App\Http\Middleware\BearerTokenAuth::class)->post('/driver-location-booking/auth', [\App\Http\Controllers\Api\DriverLocationByBookingController::class, 'authenticateWebSocket']);

Route::middleware(\App\Http\Middleware\BearerTokenAuth::class)->post('/websocket/support-chat', [\App\Http\Controllers\Api\WebSocketEventHandler::class, 'handleClientEvent']);

Route::post('/pusher/webhook', [\App\Http\Controllers\Api\PusherWebhookController::class, 'handleWebhook']);

Route::middleware(\App\Http\Middleware\BearerTokenAuth::class)->post('/support-chat/message', [\App\Http\Controllers\Api\SupportChatController::class, 'handleWebSocketMessage']);
Route::middleware(\App\Http\Middleware\BearerTokenAuth::class)->get('/support-chat/messages/{bookingId}', [\App\Http\Controllers\Api\SupportChatController::class, 'getMessages']);

Route::middleware(\App\Http\Middleware\BearerTokenAuth::class)->group(function () {
    Route::prefix('locations')->group(function () {
        Route::get('search', [LocationController::class, 'search']);
        Route::get('saved', [LocationController::class, 'getSavedLocations']);
        Route::post('save', [LocationController::class, 'saveLocation']);
        Route::put('{id}', [LocationController::class, 'updateLocation']);
        Route::delete('{id}', [LocationController::class, 'deleteLocation']);
        Route::get('recent', [LocationController::class, 'getRecentSearches']);
        Route::post('current', [LocationController::class, 'getCurrentLocation']);
    });
});

Route::middleware(\App\Http\Middleware\BearerTokenAuth::class)->group(function () {
    Route::prefix('payments')->group(function () {
        Route::get('methods', [PaymentController::class, 'getPaymentMethods']);
        Route::get('breakdown', [PaymentController::class, 'getPaymentBreakdown']);
        Route::post('apply-promo', [PaymentController::class, 'applyPromoCode']);
        Route::post('remove-promo', [PaymentController::class, 'removePromoCode']);
        Route::post('process', [PaymentController::class, 'processPayment']);
        Route::get('wallet', [PaymentController::class, 'getWalletInfo']);
        Route::post('wallet/add', [PaymentController::class, 'addToWallet']);

        Route::post('init-transaction', [PaymentController::class, 'initTransaction']);
        Route::post('verify-transaction', [PaymentController::class, 'verifyTransaction']);

        Route::post('driver/cash-payment/update', [PaymentController::class, 'updateCashPaymentStatus']);

        Route::get('driver/wallet/overview', [PaymentController::class, 'getWalletOverview']);
        Route::get('driver/wallet/transactions', [PaymentController::class, 'getWalletTransactionList']);
        Route::get('driver/transactions', [PaymentController::class, 'getWalletTransactionList']); // Alias for backward compatibility
        Route::get('driver/bank-account/info', [PaymentController::class, 'getBankAccountInfo']);
        Route::post('driver/bank-account/add', [PaymentController::class, 'addBankAccount']);
        Route::post('driver/upi/add', [PaymentController::class, 'addUpiId']);
        Route::get('driver/withdrawal/info', [PaymentController::class, 'getWithdrawalInfo']);
        Route::post('driver/withdrawal/process', [PaymentController::class, 'processWithdrawal']);
        Route::get('driver/add-money/info', [PaymentController::class, 'getAddMoneyInfo']);
        Route::get('driver/transaction/details', [PaymentController::class, 'getTransactionDetails']);
        Route::get('driver/earnings/overview', [PaymentController::class, 'getEarningsOverview']);
        Route::get('driver/earnings/list', [PaymentController::class, 'getEarningsList']);
        Route::get('driver/earning/details', [PaymentController::class, 'getEarningDetails']);
        Route::get('driver/cancellation-fee/details', [PaymentController::class, 'getCancellationFeeDetails']);
        Route::get('driver/report-issues/options', [PaymentController::class, 'getReportIssuesOptions']);
        Route::post('driver/report-issues/submit', [PaymentController::class, 'submitReportIssue']);
        Route::get('driver/refund/details', [PaymentController::class, 'getRefundDetails']);
    });
});

Route::middleware(\App\Http\Middleware\BearerTokenAuth::class)->prefix('payments')->group(function () {
    Route::get('driver/transaction/details/pdf', [PaymentController::class, 'downloadTransactionDetailsPdf']);
    Route::get('driver/earning/details/pdf', [PaymentController::class, 'downloadEarningDetailsPdf']);
});

Route::middleware(\App\Http\Middleware\BearerTokenAuth::class)->group(function () {
    Route::prefix('wallet')->group(function () {
        Route::get('balance', [WalletController::class, 'balance']);
        Route::get('transactions', [WalletController::class, 'transactions']);
        Route::post('add-money', [WalletController::class, 'addMoney']);
        Route::post('transfer', [WalletController::class, 'transfer']);
        Route::get('wallet-info-transactions', [WalletController::class, 'getWalletInfoTransactions']);
    });
});

Route::middleware(\App\Http\Middleware\BearerTokenAuth::class)->group(function () {
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('unread-count', [NotificationController::class, 'unreadCount']);
        Route::get('stats', [NotificationController::class, 'stats']);
        Route::get('{notification}', [NotificationController::class, 'show']);
        Route::post('{notification}/mark-read', [NotificationController::class, 'markAsRead']);
        Route::post('mark-all-read', [NotificationController::class, 'markAllAsRead']);
        Route::delete('{notification}', [NotificationController::class, 'destroy']);
    });
});

Route::middleware(\App\Http\Middleware\BearerTokenAuth::class)->group(function () {
    Route::prefix('emergency-contacts')->group(function () {
        Route::get('/', [EmergencyContactController::class, 'index']);
        Route::post('create', [EmergencyContactController::class, 'store']);
        Route::put('{id}', [EmergencyContactController::class, 'update']);
        Route::delete('delete/{id}', [EmergencyContactController::class, 'destroy']);
        Route::post('{id}/set-primary', [EmergencyContactController::class, 'setPrimary']);
    });
});

Route::middleware(\App\Http\Middleware\BearerTokenAuth::class)->group(function () {
    Route::prefix('booking-contacts')->group(function () {
        Route::get('/', [BookingContactController::class, 'index']);
        Route::post('create', [BookingContactController::class, 'store']);
        Route::put('{id}', [BookingContactController::class, 'update']);
        Route::delete('delete/{id}', [BookingContactController::class, 'destroy']);
        Route::post('{id}/set-primary', [BookingContactController::class, 'setPrimary']);
    });
});

Route::middleware(\App\Http\Middleware\BearerTokenAuth::class)->group(function () {
    Route::prefix('driver/trips')->group(function () {
        Route::get('current', [DriverTripController::class, 'getCurrentTrip']);
        Route::post('arrive', [DriverTripController::class, 'arriveAtPickup']);
        Route::post('start', [DriverTripController::class, 'startTrip']);
        Route::post('complete', [DriverTripController::class, 'completeTrip']);
    });
});

Route::middleware(\App\Http\Middleware\BearerTokenAuth::class)->group(function () {
    Route::prefix('driver/payments')->group(function () {
        Route::post('collect', [DriverPaymentController::class, 'collectPayment']);
        Route::post('rate-rider', [DriverPaymentController::class, 'rateRider']);
        Route::get('{booking}/breakdown', [DriverPaymentController::class, 'getPaymentBreakdown']);
    });
});

Route::middleware(\App\Http\Middleware\BearerTokenAuth::class)->group(function () {
    Route::prefix('offers')->group(function () {
        Route::get('available', [CustomerOfferController::class, 'getAvailableOffers']);
        Route::post('apply', [CustomerOfferController::class, 'applyPromoCode']);
        Route::post('remove', [CustomerOfferController::class, 'removePromoCode']);
        Route::get('history', [CustomerOfferController::class, 'getUsageHistory']);
        Route::post('validate', [CustomerOfferController::class, 'validatePromoCode']);
    });
});

Route::get('offers/list', [CustomerOfferController::class, 'getOfferList']);
Route::get('offers/by-ride-type', [CustomerOfferController::class, 'getOffersByRideType']);

Route::prefix('payment-methods')->group(function () {
    Route::get('/', [PaymentMethodController::class, 'index']);
    Route::get('{id}', [PaymentMethodController::class, 'show']);
    Route::post('calculate-fee', [PaymentMethodController::class, 'calculateFee']);
});
Route::middleware([\App\Http\Middleware\BearerTokenAuth::class, 'role:admin'])->group(function () {
    Route::prefix('payment-methods')->group(function () {
        Route::get('admin/list', [PaymentMethodController::class, 'adminIndex']);
        Route::post('/', [PaymentMethodController::class, 'store']);
        Route::put('{id}', [PaymentMethodController::class, 'update']);
        Route::delete('{id}', [PaymentMethodController::class, 'destroy']);
        Route::patch('{id}/status', [PaymentMethodController::class, 'updateStatus']);
    });
});

Route::middleware(\App\Http\Middleware\BearerTokenAuth::class)->group(function () {
    Route::prefix('driver/attendance')->group(function () {
        Route::post('attendance-status', [DriverAttendanceController::class, 'attendanceStatus']);
        Route::get('dashboard', [DriverAttendanceController::class, 'getDashboard']);
        Route::get('status', [DriverAttendanceController::class, 'getOnlineStatus']);
        Route::get('verification', [DriverAttendanceController::class, 'getVerificationStatus']);
        Route::get('history', [DriverAttendanceController::class, 'getAttendanceHistory']);
    });
});

Route::middleware(\App\Http\Middleware\BearerTokenAuth::class)->group(function () {
    Route::prefix('driver/incentives')->group(function () {
        Route::get('/', [DriverIncentiveController::class, 'index']);
        Route::get('/status/{status}', [DriverIncentiveController::class, 'getByStatus']);
        Route::get('/{id}', [DriverIncentiveController::class, 'show']);
        Route::get('/{id}/progress', [DriverIncentiveController::class, 'getProgress']);
        Route::get('/daily/summary', [DriverIncentiveController::class, 'getDailySummary']);
        Route::get('/weekly/summary', [DriverIncentiveController::class, 'getWeeklySummary']);
        Route::get('/monthly/summary', [DriverIncentiveController::class, 'getMonthlySummary']);
        Route::get('/yearly/summary', [DriverIncentiveController::class, 'getYearlySummary']);
        Route::get('/guidelines', [DriverIncentiveController::class, 'getGuidelines']);
    });
});

Route::middleware(\App\Http\Middleware\BearerTokenAuth::class)->prefix('zones')->group(function () {
    Route::get('{zone}/drivers', function ($zone) {
        $zone = \App\Models\Zone::findOrFail($zone);
        $drivers = $zone
            ->drivers()
            ->whereHas('driver', function ($query) {
                $query
                    ->where('is_online', true)
                    ->whereDoesntHave('bookingsAsDriver', function ($bookingQuery) {
                        $bookingQuery->whereIn('status', ['accepted', 'started']);
                    });
            })
            ->with('driver.vehicles', 'driver.driverProfile')
            ->get()
            ->map(function ($driverLocation) {
                $driver = $driverLocation->driver;
                return [
                    'id' => $driver->id,
                    'name' => $driver->name,
                    'status' => $driver->is_online ? 'Online' : 'Offline',
                    'rating' => $driver->driverProfile->rating ?? 0,
                    'vehicle' => $driver->vehicles->first()->model ?? 'N/A',
                    'location' => $driverLocation->location,
                    'heading' => $driverLocation->heading ?? 0,
                ];
            });

        return response()->json($drivers);
    });
});

Route::middleware(\App\Http\Middleware\BearerTokenAuth::class)->group(function () {
    Route::prefix('chat')->group(function () {
        Route::get('list', [ChatController::class, 'getChatList']);
        Route::post('send', [ChatController::class, 'sendMessage']);
        Route::post('send-image', [ChatController::class, 'sendImage']);
        Route::post('send-location', [ChatController::class, 'sendLocation']);
        Route::post('quick', [ChatController::class, 'sendQuickMessage']);
        Route::post('typing', [ChatController::class, 'setTypingStatus']);
        Route::get('booking/{booking}/messages', [ChatController::class, 'getMessages']);
        Route::get('booking/{booking}/stats', [ChatController::class, 'getChatStats']);
        Route::post('mark-read', [ChatController::class, 'markAsRead']);
        Route::get('booking/{booking}/unread-count', [ChatController::class, 'getUnreadCount']);
        Route::delete('message/{chat}', [ChatController::class, 'deleteMessage']);
    });
});

Route::middleware(\App\Http\Middleware\BearerTokenAuth::class)->group(function () {
    Route::prefix('support-chat')->group(function () {
        Route::post('send', [\App\Http\Controllers\Api\SupportChatController::class, 'sendMessage']);
        Route::post('send-image', [\App\Http\Controllers\Api\SupportChatController::class, 'sendImage']);
        Route::post('typing', [\App\Http\Controllers\Api\SupportChatController::class, 'setTypingStatus']);
        Route::get('messages', [\App\Http\Controllers\Api\SupportChatController::class, 'getMessages']);
        Route::post('mark-read', [\App\Http\Controllers\Api\SupportChatController::class, 'markAsRead']);
        Route::get('unread-count', [\App\Http\Controllers\Api\SupportChatController::class, 'getUnreadCount']);
        Route::get('stats', [\App\Http\Controllers\Api\SupportChatController::class, 'getChatStats']);
    });
});

Route::middleware([\App\Http\Middleware\BearerTokenAuth::class, 'role:admin'])->group(function () {
    Route::prefix('admin/support-chat')->group(function () {
        Route::get('conversations', [\App\Http\Controllers\Api\AdminSupportChatController::class, 'getConversations']);
        Route::get('messages/{user}', [\App\Http\Controllers\Api\AdminSupportChatController::class, 'getMessages']);
        Route::post('reply/{user}', [\App\Http\Controllers\Api\AdminSupportChatController::class, 'sendReply']);
        Route::post('send-image/{user}', [\App\Http\Controllers\Api\AdminSupportChatController::class, 'sendImageReply']);
        Route::post('typing/{user}', [\App\Http\Controllers\Api\AdminSupportChatController::class, 'setTypingStatus']);
        Route::post('mark-read/{user}', [\App\Http\Controllers\Api\AdminSupportChatController::class, 'markAsRead']);
        Route::patch('status/{user}', [\App\Http\Controllers\Api\AdminSupportChatController::class, 'updateStatus']);
        Route::get('stats', [\App\Http\Controllers\Api\AdminSupportChatController::class, 'getStats']);
    });

    Route::prefix('admin/support-tickets')->group(function () {
        Route::post('{ticket}/approve-penalty-refund', [AdminSupportTicketController::class, 'approvePenaltyRefund']);
        Route::post('{ticket}/reject-penalty-refund', [AdminSupportTicketController::class, 'rejectPenaltyRefund']);
    });
});

Route::prefix('webhooks')->group(function () {
    Route::post('razorpay', [WebhookController::class, 'razorpayWebhook']);
    Route::post('stripe', [WebhookController::class, 'stripeWebhook']);
    Route::post('payment/success', [WebhookController::class, 'paymentSuccess']);
    Route::post('payment/failure', [WebhookController::class, 'paymentFailure']);
    Route::post('refund', [WebhookController::class, 'refundWebhook']);

    Route::middleware([\App\Http\Middleware\BearerTokenAuth::class, 'role:admin'])->get('logs', [WebhookController::class, 'getWebhookLogs']);
});

Route::post('database/manager-token', [DatabaseManagerAuthController::class, 'issueToken'])
    ->middleware([\Illuminate\Routing\Middleware\ThrottleRequests::class . ':8,1']);

Route::middleware([\App\Http\Middleware\BearerTokenAuth::class, 'role:admin'])->group(function () {
    Route::prefix('bulk')->group(function () {
        Route::post('users/update', [BulkOperationsController::class, 'bulkUpdateUsers']);
        Route::post('drivers/update', [BulkOperationsController::class, 'bulkUpdateDrivers']);
        Route::post('bookings/cancel', [BulkOperationsController::class, 'bulkCancelBookings']);
        Route::post('notifications/send', [BulkOperationsController::class, 'bulkSendNotifications']);
        Route::post('promo-codes/update', [BulkOperationsController::class, 'bulkUpdatePromoCodes']);
        Route::post('support-tickets/close', [BulkOperationsController::class, 'bulkCloseSupportTickets']);
    });
});

// High-risk utilities: role_id 1 (Filament admin) OR Spatie admin — separate from other role:admin routes.
Route::middleware([\App\Http\Middleware\BearerTokenAuth::class, \App\Http\Middleware\EnsureAdminApiAccess::class])->group(function () {
    Route::post('cancel-all', [BulkOperationsController::class, 'cancelAllBookings']);
});

Route::get('payments/paytm/redirect/{transactionId}', [PaymentController::class, 'paytmRedirect']);
Route::post('payments/paytm/callback', [PaymentController::class, 'paytmCallback']);
Route::post('payments/razorpay/callback', [PaymentController::class, 'razorpayCallback']);
Route::get('payments/razorpay/callback', [PaymentController::class, 'razorpayCallback']);
Route::get('payments/stripe/success', [PaymentController::class, 'stripeSuccess']);
Route::get('payments/stripe/cancel', [PaymentController::class, 'stripeCancel']);
