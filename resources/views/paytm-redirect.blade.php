<!DOCTYPE html>
<html>

<head>
    <title>Redirecting to Paytm...</title>
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

        .loader-container {
            text-align: center;
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        }

        .loader {
            border: 5px solid #f3f3f3;
            border-top: 5px solid #667eea;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        h2 {
            color: #333;
            margin: 0 0 10px;
        }

        p {
            color: #666;
            margin: 0;
        }
    </style>
</head>

<body>
    <div class="loader-container">
        <div class="loader"></div>
        <h2>Redirecting to Paytm</h2>
        <p>Please wait while we redirect you to the payment gateway...</p>
    </div>

    <form id="paytmForm" method="POST" action="{{ $formUrl }}">
        @foreach ($params as $key => $value)
            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
        @endforeach
    </form>

    <script>
        // Auto-submit the form immediately
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('paytmForm').submit();
        });
    </script>
</body>

</html>
