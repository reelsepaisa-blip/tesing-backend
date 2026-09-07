<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Taxi Booking App')</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    <!-- Tailwind CSS via CDN (no Vite needed for static pages) -->
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        body {
            font-family: 'Figtree', sans-serif;
            background-color: #f5f5f5;
        }

        .content-container {
            max-width: 900px;
            margin: 0 auto;
            padding: 2rem;
            background: white;
            min-height: 100vh;
        }

        @media (max-width: 640px) {
            .content-container {
                padding: 1rem;
            }
        }
    </style>
</head>

<body>
    <div class="content-container">
        @yield('content')
    </div>
</body>

</html>
