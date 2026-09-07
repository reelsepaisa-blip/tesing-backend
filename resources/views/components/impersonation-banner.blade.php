@if(session()->has('impersonate'))
    <div class="bg-warning-500 text-white px-4 py-2 text-sm text-center">
        <div class="max-w-7xl mx-auto">
            You are currently impersonating {{ auth()->user()->name }}.
            <a href="{{ route('impersonate.leave') }}" class="underline font-medium">
                Return to your account
            </a>
        </div>
    </div>
@endif
