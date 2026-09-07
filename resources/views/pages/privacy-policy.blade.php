@extends('pages.layout')

@section('title', $policy->title ?? 'Privacy Policy')

@section('content')
    <div class="space-y-6">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-3xl font-bold text-gray-900">{{ $policy->title }}</h1>
        </div>

        @if ($policy->intro_text)
            <div class="text-lg text-gray-700 mb-6">
                {!! nl2br(e($policy->intro_text)) !!}
            </div>
        @endif

        @if ($policy->sections && count($policy->sections) > 0)
            <div class="space-y-8">
                @foreach ($policy->sections as $section)
                    <div class="border-l-4 border-blue-500 pl-4 py-2">
                        <h2 class="text-2xl font-semibold text-gray-900 mb-3">{{ $section['title'] ?? '' }}</h2>

                        @if ($section['content'])
                            <div class="text-gray-700 mb-3">
                                {!! $section['content'] !!}
                            </div>
                        @endif

                        @if (isset($section['items']) && is_array($section['items']) && count($section['items']) > 0)
                            <ul class="list-disc list-inside space-y-2 text-gray-700 ml-4">
                                @foreach ($section['items'] as $item)
                                    <li>{{ $item['item'] ?? '' }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        @if ($policy->data_sharing_text)
            <div class="mt-6 p-4 bg-gray-50 rounded-lg">
                <h3 class="text-xl font-semibold text-gray-900 mb-2">Data Sharing</h3>
                <p class="text-gray-700">{{ $policy->data_sharing_text }}</p>
            </div>
        @endif

        @if ($policy->user_rights_text)
            <div class="mt-6 p-4 bg-gray-50 rounded-lg">
                <h3 class="text-xl font-semibold text-gray-900 mb-2">Your Rights</h3>
                <p class="text-gray-700">{{ $policy->user_rights_text }}</p>
            </div>
        @endif

        @if ($policy->conclusion_text)
            <div class="mt-8 pt-4 border-t border-gray-200">
                <p class="text-gray-600">{{ $policy->conclusion_text }}</p>
            </div>
        @endif

        {{-- Contact Section --}}
        <div class="mt-8 p-6 bg-gradient-to-r from-blue-50 to-indigo-50 rounded-lg border border-blue-200">
            <h3 class="text-2xl font-bold text-gray-900 mb-4">Get in touch</h3>
            <div class="grid md:grid-cols-2 gap-6">
                <div class="flex items-start space-x-3">
                    <svg class="w-6 h-6 text-blue-600 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                    </svg>
                    <div>
                        <p class="text-sm font-semibold text-gray-700 mb-1">Need support?</p>
                        <p>info@netsofters.in</p>
                    </div>
                </div>
                <div class="flex items-start space-x-3">
                    <svg class="w-6 h-6 text-blue-600 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                    </svg>
                    <div>
                        <p class="text-sm font-semibold text-gray-700 mb-1">Customer care?</p>
                        <p>+91 9979880093</p>
                    </div>
                </div>
            </div>
        </div>

        @if ($policy->effective_date || $policy->version)
            <div class="mt-6 text-sm text-gray-500">
                @if ($policy->version)
                    <p><strong>Version:</strong> {{ $policy->version }}</p>
                @endif
                @if ($policy->effective_date)
                    <p><strong>Effective Date:</strong>
                        {{ \Carbon\Carbon::parse($policy->effective_date)->format('F d, Y') }}</p>
                @endif
                @if ($policy->last_updated_at)
                    <p><strong>Last Updated:</strong>
                        {{ \Carbon\Carbon::parse($policy->last_updated_at)->format('F d, Y') }}</p>
                @endif
            </div>
        @endif
    </div>
@endsection
