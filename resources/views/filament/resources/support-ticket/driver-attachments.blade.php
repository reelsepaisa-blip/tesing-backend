@php
    $record = $getRecord();
    $attachments = $record?->attachments()
        ->where('is_internal', false)
        ->where(function($query) use ($record) {
            if ($record) {
                $query->where('user_id', $record->user_id)
                    ->orWhereNull('user_id');
            }
        })
        ->get()
        ->filter(fn($attachment) => $attachment->isImage());
@endphp

<div class="space-y-4">
    @if($attachments->isEmpty())
        <div class="text-sm text-gray-500 dark:text-gray-400 p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
            <p>No images uploaded by driver.</p>
        </div>
    @else
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
            @foreach($attachments as $attachment)
                <div class="relative group">
                    <a 
                        href="{{ Storage::disk('public')->url($attachment->file_path) }}" 
                        target="_blank"
                        class="block aspect-square rounded-lg overflow-hidden border border-gray-200 dark:border-gray-700 hover:border-primary-500 dark:hover:border-primary-400 transition-colors"
                    >
                        <img 
                            src="{{ Storage::disk('public')->url($attachment->file_path) }}" 
                            alt="{{ $attachment->original_name ?? $attachment->name ?? 'Image' }}"
                            class="w-full h-full object-cover"
                            loading="lazy"
                        >
                        <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-30 transition-opacity flex items-center justify-center">
                            <svg class="w-8 h-8 text-white opacity-0 group-hover:opacity-100 transition-opacity" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7"></path>
                            </svg>
                        </div>
                    </a>
                    <div class="mt-2 text-xs text-gray-600 dark:text-gray-400">
                        <p class="truncate" title="{{ $attachment->original_name ?? $attachment->name ?? 'Image' }}">
                            {{ $attachment->original_name ?? $attachment->name ?? 'Image' }}
                        </p>
                        <p class="text-gray-500 dark:text-gray-500">
                            {{ $attachment->getFormattedSize() }}
                            @if($attachment->created_at)
                                · {{ $attachment->created_at->format('M d, Y') }}
                            @endif
                        </p>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>

