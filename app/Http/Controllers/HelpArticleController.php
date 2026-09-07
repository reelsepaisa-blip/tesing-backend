<?php

namespace App\Http\Controllers;

use App\Models\HelpArticle;
use Illuminate\Http\Request;

class HelpArticleController extends Controller
{
    
    public function show(HelpArticle $article)
    {

        if (!$article->is_published || $article->published_at > now()) {
            abort(404);
        }

        $article->incrementViewCount();

        $article->load(['category', 'author', 'tags']);

        $relatedArticles = HelpArticle::published()
            ->where('category_id', $article->category_id)
            ->where('id', '!=', $article->id)
            ->limit(5)
            ->get();

        return view('help.articles.show', compact('article', 'relatedArticles'));
    }

    
    public function index(Request $request)
    {
        $query = HelpArticle::published()
            ->with(['category', 'author', 'tags'])
            ->latest('published_at');

        if ($request->has('category')) {
            $query->whereHas('category', function ($q) use ($request) {
                $q->where('slug', $request->category);
            });
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('content', 'like', "%{$search}%")
                    ->orWhere('excerpt', 'like', "%{$search}%");
            });
        }

        $articles = $query->paginate(12);

        return view('help.articles.index', compact('articles'));
    }
}
