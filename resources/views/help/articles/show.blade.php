<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $article->meta_title ?: $article->title }} - Help Center</title>
    <meta name="description" content="{{ $article->meta_description ?: $article->excerpt }}">
    <meta name="keywords"
        content="{{ is_array($article->meta_keywords) ? implode(', ', $article->meta_keywords) : $article->meta_keywords }}">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Prism.js for code highlighting -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-core.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/autoloader/prism-autoloader.min.js"></script>
</head>

<body class="bg-gray-50">
    <div class="min-h-screen">
        <!-- Header -->
        <header class="bg-white shadow-sm border-b">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between items-center py-4">
                    <div class="flex items-center">
                        <a href="{{ route('help.articles.index') }}"
                            class="text-blue-600 hover:text-blue-800 font-medium">
                            ← Back to Help Center
                        </a>
                    </div>
                    <div class="text-sm text-gray-500">
                        {{ $article->published_at->format('M d, Y') }}
                    </div>
                </div>
            </div>
        </header>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-8">
                <!-- Main Content -->
                <div class="lg:col-span-3">
                    <article class="bg-white rounded-lg shadow-sm p-8">
                        <!-- Article Header -->
                        <header class="mb-8">
                            <div class="flex items-center space-x-2 mb-4">
                                @if ($article->category)
                                    <span
                                        class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
                                        {{ $article->category->name }}
                                    </span>
                                @endif
                                @if ($article->is_featured)
                                    <span
                                        class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-yellow-100 text-yellow-800">
                                        Featured
                                    </span>
                                @endif
                                @if ($article->isNew())
                                    <span
                                        class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">
                                        New
                                    </span>
                                @endif
                            </div>

                            <h1 class="text-3xl font-bold text-gray-900 mb-4">{{ $article->title }}</h1>

                            <div class="flex items-center space-x-4 text-sm text-gray-500">
                                <div class="flex items-center">
                                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z">
                                        </path>
                                    </svg>
                                    {{ $article->author->name }}
                                </div>
                                <div class="flex items-center">
                                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z">
                                        </path>
                                    </svg>
                                    {{ $article->view_count }} views
                                </div>
                                <div class="flex items-center">
                                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                    {{ $article->getReadingTime() }} min read
                                </div>
                            </div>
                        </header>

                        <!-- Article Content -->
                        <div class="prose prose-lg max-w-none">
                            {!! $article->content !!}
                        </div>

                        <!-- Article Footer -->
                        <footer class="mt-8 pt-8 border-t">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-4">
                                    <span class="text-sm text-gray-500">Was this article helpful?</span>
                                    <div class="flex space-x-2">
                                        <button
                                            class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800 hover:bg-green-200 transition-colors">
                                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M14 10h4.764a2 2 0 011.789 2.894l-3.5 7A2 2 0 0115.263 21h-4.017c-.163 0-.326-.02-.485-.06L7 20m7-10V18m-7-8a2 2 0 01-2-2V5a2 2 0 012-2h2.343M11 7.06l-1.5-1.5M11 7.06l1.5-1.5">
                                                </path>
                                            </svg>
                                            Yes ({{ $article->helpful_count }})
                                        </button>
                                        <button
                                            class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-red-100 text-red-800 hover:bg-red-200 transition-colors">
                                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M10 14H5.236a2 2 0 01-1.789-2.894l3.5-7A2 2 0 018.737 3h4.018c.163 0 .326.02.485.06L17 4m-7 10V6m7 8a2 2 0 012-2V5a2 2 0 00-2-2h-2.343M13 16.94l1.5 1.5M13 16.94l-1.5 1.5">
                                                </path>
                                            </svg>
                                            No ({{ $article->not_helpful_count }})
                                        </button>
                                    </div>
                                </div>

                                @if ($article->tags->count() > 0)
                                    <div class="flex flex-wrap gap-2">
                                        @foreach ($article->tags as $tag)
                                            <span
                                                class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-gray-100 text-gray-800">
                                                {{ $tag->name }}
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </footer>
                    </article>
                </div>

                <!-- Sidebar -->
                <div class="lg:col-span-1">
                    <div class="space-y-6">
                        <!-- Related Articles -->
                        @if ($relatedArticles->count() > 0)
                            <div class="bg-white rounded-lg shadow-sm p-6">
                                <h3 class="text-lg font-semibold text-gray-900 mb-4">Related Articles</h3>
                                <div class="space-y-4">
                                    @foreach ($relatedArticles as $related)
                                        <a href="{{ route('help.articles.show', $related) }}" class="block group">
                                            <h4
                                                class="text-sm font-medium text-gray-900 group-hover:text-blue-600 transition-colors">
                                                {{ $related->title }}
                                            </h4>
                                            <p class="text-xs text-gray-500 mt-1">
                                                {{ $related->published_at->format('M d, Y') }}
                                            </p>
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <!-- Article Stats -->
                        <div class="bg-white rounded-lg shadow-sm p-6">
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">Article Stats</h3>
                            <div class="space-y-3">
                                <div class="flex justify-between">
                                    <span class="text-sm text-gray-600">Views</span>
                                    <span class="text-sm font-medium">{{ $article->view_count }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-sm text-gray-600">Helpful</span>
                                    <span class="text-sm font-medium">{{ $article->helpful_count }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-sm text-gray-600">Not Helpful</span>
                                    <span class="text-sm font-medium">{{ $article->not_helpful_count }}</span>
                                </div>
                                @if ($article->helpful_count > 0 || $article->not_helpful_count > 0)
                                    <div class="flex justify-between">
                                        <span class="text-sm text-gray-600">Helpful Rate</span>
                                        <span
                                            class="text-sm font-medium">{{ $article->getHelpfulPercentage() }}%</span>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Custom Styles for Prose -->
    <style>
        .prose {
            color: #374151;
        }

        .prose h1,
        .prose h2,
        .prose h3,
        .prose h4,
        .prose h5,
        .prose h6 {
            color: #111827;
            font-weight: 600;
        }

        .prose h1 {
            font-size: 2rem;
            margin-top: 0;
            margin-bottom: 1rem;
        }

        .prose h2 {
            font-size: 1.5rem;
            margin-top: 2rem;
            margin-bottom: 1rem;
        }

        .prose h3 {
            font-size: 1.25rem;
            margin-top: 1.5rem;
            margin-bottom: 0.75rem;
        }

        .prose p {
            margin-bottom: 1rem;
            line-height: 1.7;
        }

        .prose ul,
        .prose ol {
            margin-bottom: 1rem;
            padding-left: 1.5rem;
        }

        .prose li {
            margin-bottom: 0.5rem;
        }

        .prose blockquote {
            border-left: 4px solid #e5e7eb;
            padding-left: 1rem;
            margin: 1.5rem 0;
            font-style: italic;
            color: #6b7280;
        }

        .prose code {
            background-color: #f3f4f6;
            padding: 0.125rem 0.25rem;
            border-radius: 0.25rem;
            font-size: 0.875em;
        }

        .prose pre {
            background-color: #1f2937;
            color: #f9fafb;
            padding: 1rem;
            border-radius: 0.5rem;
            overflow-x: auto;
            margin: 1rem 0;
        }

        .prose pre code {
            background-color: transparent;
            padding: 0;
            color: inherit;
        }

        .prose img {
            max-width: 100%;
            height: auto;
            border-radius: 0.5rem;
            margin: 1rem 0;
        }

        .prose table {
            width: 100%;
            border-collapse: collapse;
            margin: 1rem 0;
        }

        .prose th,
        .prose td {
            border: 1px solid #e5e7eb;
            padding: 0.5rem;
            text-align: left;
        }

        .prose th {
            background-color: #f9fafb;
            font-weight: 600;
        }
    </style>
</body>

</html>
