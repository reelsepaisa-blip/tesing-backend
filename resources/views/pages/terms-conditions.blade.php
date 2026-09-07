@extends('pages.layout')

@section('title', $terms->title ?? 'Terms & Conditions')

@section('content')
    <div class="space-y-6">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-3xl font-bold text-gray-900">{{ $terms->title }}</h1>
        </div>

        @if ($terms->intro_text)
            <div class="text-lg text-gray-700 mb-6">
                {!! nl2br(e($terms->intro_text)) !!}
            </div>
        @endif

        @if ($terms->sections && count($terms->sections) > 0)
            <div class="space-y-6">
                @foreach ($terms->sections as $index => $section)
                    <div class="border-l-4 border-orange-500 pl-4 py-2">
                        <h2 class="text-xl font-semibold text-gray-900 mb-2">
                            {{ $index + 1 }}. {{ $section['title'] ?? '' }}
                        </h2>
                        <div class="text-gray-700">
                            {!! nl2br(e(strip_tags($section['content'] ?? ''))) !!}
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($terms->conclusion_text)
            <div class="mt-8 pt-4 border-t border-gray-200">
                <p class="text-gray-600">{{ $terms->conclusion_text }}</p>
            </div>
        @endif

        @if ($terms->effective_date || $terms->version)
            <div class="mt-6 text-sm text-gray-500">
                @if ($terms->version)
                    <p><strong>Version:</strong> {{ $terms->version }}</p>
                @endif
                @if ($terms->effective_date)
                    <p><strong>Effective Date:</strong>
                        {{ \Carbon\Carbon::parse($terms->effective_date)->format('F d, Y') }}</p>
                @endif
                @if ($terms->last_updated_at)
                    <p><strong>Last Updated:</strong> {{ \Carbon\Carbon::parse($terms->last_updated_at)->format('F d, Y') }}
                    </p>
                @endif
            </div>
        @endif
    </div>
@endsection
