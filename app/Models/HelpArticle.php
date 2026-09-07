<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class HelpArticle extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'category_id',
        'title',
        'slug',
        'content',
        'excerpt',
        'author_id',
        'is_featured',
        'is_published',
        'published_at',
        'order',
        'view_count',
        'helpful_count',
        'not_helpful_count',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'related_articles',
    ];

    protected $casts = [
        'is_featured' => 'boolean',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
        'order' => 'integer',
        'view_count' => 'integer',
        'helpful_count' => 'integer',
        'not_helpful_count' => 'integer',
        'meta_keywords' => 'array',
        'related_articles' => 'array',
    ];

    public function category()
    {
        return $this->belongsTo(HelpCategory::class, 'category_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function tags()
    {
        return $this->belongsToMany(HelpTag::class, 'help_article_tags', 'article_id', 'tag_id');
    }

    public function attachments()
    {
        return $this->hasMany(HelpArticleAttachment::class);
    }

    public function feedback()
    {
        return $this->hasMany(HelpArticleFeedback::class);
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true)
            ->where('published_at', '<=', now());
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }

    public function scopePopular($query)
    {
        return $query->orderByDesc('view_count');
    }

    public function scopeHelpful($query)
    {
        return $query->orderByRaw('(helpful_count / (helpful_count + not_helpful_count)) DESC')
            ->having('helpful_count', '>', 0);
    }

    public function incrementViewCount(): void
    {
        $this->increment('view_count');
    }

    public function recordFeedback(bool $isHelpful): void
    {
        if ($isHelpful) {
            $this->increment('helpful_count');
        } else {
            $this->increment('not_helpful_count');
        }
    }

    public function getHelpfulPercentage(): float
    {
        $total = $this->helpful_count + $this->not_helpful_count;
        if ($total === 0) {
            return 0;
        }
        return round(($this->helpful_count / $total) * 100, 2);
    }

    public function getReadingTime(): int
    {
        $wordsPerMinute = 200;
        $wordCount = str_word_count(strip_tags($this->content));
        return ceil($wordCount / $wordsPerMinute);
    }

    public function isNew(): bool
    {
        return $this->published_at?->diffInDays(now()) <= 7;
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($article) {
            if (!$article->slug) {
                $article->slug = str()->slug($article->title);
            }

            if (!$article->author_id) {
                $article->author_id = auth()->id();
            }

            if (!$article->excerpt && $article->content) {
                $article->excerpt = str()->limit(strip_tags($article->content), 160);
            }
        });

        static::updating(function ($article) {
            if ($article->isDirty('title') && !$article->isDirty('slug')) {
                $article->slug = str()->slug($article->title);
            }

            if ($article->isDirty('content') && !$article->isDirty('excerpt')) {
                $article->excerpt = str()->limit(strip_tags($article->content), 160);
            }
        });
    }
}
