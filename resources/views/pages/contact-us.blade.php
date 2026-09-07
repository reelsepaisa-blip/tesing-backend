@extends('pages.layout')

@section('title', 'Contact Us')

@section('content')
    <div class="space-y-6">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-3xl font-bold text-gray-900">Contact us</h1>
        </div>

        @if ($contactUs->intro_text)
            <div class="text-lg text-gray-700 mb-8">
                {!! nl2br(e($contactUs->intro_text)) !!}
            </div>
        @endif

        <div class="space-y-4">
            @if ($contactUs->email)
                <div class="flex items-start p-4 bg-gray-50 rounded-lg">
                    <div class="flex-shrink-0 w-12 h-12 bg-orange-500 rounded-full flex items-center justify-center mr-4">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z">
                            </path>
                        </svg>
                    </div>
                    <div>
                        <p class="font-semibold text-gray-900 mb-1">Email</p>
                        <a href="mailto:{{ $contactUs->email }}"
                            class="text-blue-600 hover:underline">{{ $contactUs->email }}</a>
                    </div>
                </div>
            @endif

            @if ($contactUs->phone)
                <div class="flex items-start p-4 bg-gray-50 rounded-lg">
                    <div class="flex-shrink-0 w-12 h-12 bg-orange-500 rounded-full flex items-center justify-center mr-4">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z">
                            </path>
                        </svg>
                    </div>
                    <div>
                        <p class="font-semibold text-gray-900 mb-1">Phone</p>
                        <a href="tel:{{ $contactUs->phone }}"
                            class="text-blue-600 hover:underline">{{ $contactUs->phone }}</a>
                    </div>
                </div>
            @endif

            @if ($contactUs->office_address)
                <div class="flex items-start p-4 bg-gray-50 rounded-lg">
                    <div class="flex-shrink-0 w-12 h-12 bg-orange-500 rounded-full flex items-center justify-center mr-4">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z">
                            </path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="font-semibold text-gray-900 mb-1">Office Address</p>
                        <p class="text-gray-700">{{ $contactUs->office_address }}</p>
                    </div>
                </div>
            @endif
        </div>

        {{-- @if ($contactUs->additional_contacts && count($contactUs->additional_contacts) > 0)
            <div class="mt-6">
                <h2 class="text-xl font-semibold text-gray-900 mb-4">Additional Contact Methods</h2>
                <div class="space-y-4">
                    @foreach ($contactUs->additional_contacts as $contact)
                        <div class="flex items-start p-4 bg-gray-50 rounded-lg">
                            <div
                                class="flex-shrink-0 w-12 h-12 bg-orange-500 rounded-full flex items-center justify-center mr-4">
                                <span
                                    class="text-white font-semibold">{{ strtoupper(substr($contact['type'] ?? '', 0, 1)) }}</span>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-900 mb-1">
                                    {{ $contact['label'] ?? ucfirst($contact['type'] ?? 'Contact') }}</p>
                                <p class="text-gray-700">{{ $contact['value'] ?? '' }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif --}}

        @if ($contactUs->working_hours)
            <div class="mt-6 p-4 bg-blue-50 rounded-lg">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Working Hours</h3>
                <p class="text-gray-700">{{ $contactUs->working_hours }}</p>
            </div>
        @endif

        @if ($contactUs->support_message)
            <div class="mt-6 p-4 bg-gray-50 rounded-lg">
                <p class="text-gray-700">{{ $contactUs->support_message }}</p>
            </div>
        @endif
    </div>
@endsection
