<!DOCTYPE html>
<html>
<head>
    <title>Payment Successful</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .container {
            text-align: center;
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            max-width: 400px;
        }
        .success-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            background: #4CAF50;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .success-icon::after {
            content: '✓';
            color: white;
            font-size: 50px;
            font-weight: bold;
        }
        h1 {
            color: #333;
            margin: 0 0 10px;
        }
        p {
            color: #666;
            margin: 10px 0;
        }
        .transaction-id {
            background: #f5f5f5;
            padding: 10px;
            border-radius: 5px;
            margin: 20px 0;
            word-break: break-all;
        }
        .close-btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            margin-top: 20px;
        }
        .close-btn:hover {
            background: #5568d3;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="success-icon"></div>
        <h1>Payment Successful!</h1>
        <p>{{ $message ?? 'Your payment has been processed successfully.' }}</p>
        
        @if(isset($transaction_id))
        <div class="transaction-id">
            <strong>Transaction ID:</strong><br>
            {{ $transaction_id }}
        </div>
        @endif
        
        @if(isset($amount))
        <p><strong>Amount:</strong> ₹{{ $amount }}</p>
        @endif
        
        <button class="close-btn" onclick="closeWindow()">Close</button>
    </div>

    <script>
        function closeWindow() {
            // Try to close the window (works in WebView)
            window.close();
            
            // If window.close() doesn't work, redirect back
            setTimeout(function() {
                window.history.back();
            }, 100);
        }
        
        // Auto-close after 5 seconds
        setTimeout(function() {
            closeWindow();
        }, 5000);
    </script>
</body>
</html>

