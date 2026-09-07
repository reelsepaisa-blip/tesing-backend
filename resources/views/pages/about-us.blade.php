@extends('pages.layout')

@section('title', $aboutUs->title ?? 'About Us')

@section('content')
    <div class="space-y-6">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-3xl font-bold text-gray-900">{{ $aboutUs->title ?? 'About Us' }}</h1>
        </div>

        @if ($aboutUs->intro_text)
            <div class="text-lg text-gray-700 mb-6">
                {!! nl2br(e($aboutUs->intro_text)) !!}
            </div>
        @endif

        @if ($aboutUs->content)
            <div class="prose max-w-none mb-6">
                {!! $aboutUs->content !!}
            </div>
        @endif

        @if ($aboutUs->sections && count($aboutUs->sections) > 0)
            <div class="space-y-6">
                @foreach ($aboutUs->sections as $section)
                    <div class="border-l-4 border-yellow-500 pl-4">
                        <h2 class="text-2xl font-semibold text-gray-900 mb-3">{{ $section['title'] ?? '' }}</h2>
                        <div class="text-gray-700 prose max-w-none">
                            {!! $section['content'] ?? '' !!}
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endsection
