<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class HelpCategory extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'icon',
        'parent_id',
        'order',
        'is_active',
        'meta_title',
        'meta_description',
        'meta_keywords',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'order' => 'integer',
        'meta_keywords' => 'array',
    ];

    public function parent()
    {
        return $this->belongsTo(HelpCategory::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(HelpCategory::class, 'parent_id');
    }

    public function articles()
    {
        return $this->hasMany(HelpArticle::class, 'category_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeParentOnly($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }

    public function getFullHierarchy(): string
    {
        $hierarchy = [$this->name];
        $parent = $this->parent;

        while ($parent) {
            array_unshift($hierarchy, $parent->name);
            $parent = $parent->parent;
        }

        return implode(' > ', $hierarchy);
    }

    public function getAllChildren(): array
    {
        $children = [];
        foreach ($this->children as $child) {
            $children[] = $child;
            $children = array_merge($children, $child->getAllChildren());
        }
        return $children;
    }

    public function hasArticles(): bool
    {
        return $this->articles()->count() > 0;
    }

    public function isParent(): bool
    {
        return $this->children()->count() > 0;
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($category) {
            if (!$category->slug) {
                $category->slug = str()->slug($category->name);
            }
        });

        static::updating(function ($category) {
            if ($category->isDirty('name') && !$category->isDirty('slug')) {
                $category->slug = str()->slug($category->name);
            }
        });
    }
}








