<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class HelpArticleFeedback extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'article_id',
        'user_id',
        'is_helpful',
        'comment',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'is_helpful' => 'boolean',
    ];

    public function article()
    {
        return $this->belongsTo(HelpArticle::class, 'article_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeHelpful($query)
    {
        return $query->where('is_helpful', true);
    }

    public function scopeUnhelpful($query)
    {
        return $query->where('is_helpful', false);
    }

    public function scopeWithComments($query)
    {
        return $query->whereNotNull('comment');
    }

    public function isFromSameUser(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    public function isFromSameIp(string $ip): bool
    {
        return $this->ip_address === $ip;
    }

    protected static function boot()
    {
        parent::boot();

        static::created(function ($feedback) {
            if ($feedback->is_helpful) {
                $feedback->article->increment('helpful_count');
            } else {
                $feedback->article->increment('not_helpful_count');
            }
        });

        static::deleted(function ($feedback) {
            if ($feedback->is_helpful) {
                $feedback->article->decrement('helpful_count');
            } else {
                $feedback->article->decrement('not_helpful_count');
            }
        });
    }
}








