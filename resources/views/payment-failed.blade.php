<!DOCTYPE html>
<html>
<head>
    <title>Payment Failed</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        .container {
            text-align: center;
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            max-width: 400px;
        }
        .error-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            background: #f44336;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .error-icon::after {
            content: '✕';
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
        .error-message {
            background: #ffebee;
            padding: 10px;
            border-radius: 5px;
            margin: 20px 0;
            color: #c62828;
        }
        .close-btn {
            background: #f44336;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            margin-top: 20px;
        }
        .close-btn:hover {
            background: #d32f2f;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="error-icon"></div>
        <h1>Payment Failed</h1>
        <p>{{ $message ?? 'Your payment could not be processed.' }}</p>
        
        @if(isset($error))
        <div class="error-message">
            <strong>Error:</strong><br>
            {{ $error }}
        </div>
        @endif
        
        @if(isset($transaction_id))
        <p><small>Transaction ID: {{ $transaction_id }}</small></p>
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
    </script>
</body>
</html>

