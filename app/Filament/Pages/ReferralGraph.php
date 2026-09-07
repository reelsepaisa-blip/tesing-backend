<?php

namespace App\Filament\Pages;

use App\Models\User;
use Filament\Pages\Page;

class ReferralGraph extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-share';
    protected string $view = 'filament.pages.referral-graph';
    protected static string | \UnitEnum | null $navigationGroup = 'Marketing';
    protected static ?int $navigationSort = 6;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public ?int $userId = null;
    public array $tree = [];

    public function mount(): void
    {
        $this->userId = null;
        $this->tree = [];
    }

    public function loadGraph(): void
    {
        if (!$this->userId) {
            $this->tree = [];
            return;
        }

        $root = User::find($this->userId);
        if (!$root) {
            $this->tree = [];
            return;
        }

        $this->tree = $this->buildTree($root, 3); // limit depth to 3 for safety
    }

    protected function buildTree(User $user, int $depth): array
    {
        $node = [
            'id' => $user->id,
            'name' => $user->name ?? '',
            'phone' => $user->phone ?? '',
            'referral_code' => $user->referral_code ?? '',
            'children' => [],
        ];

        if ($depth <= 0) {
            return $node;
        }

        $children = User::where('referred_by', $user->id)
            ->limit(50)
            ->get();

        foreach ($children as $child) {
            $node['children'][] = $this->buildTree($child, $depth - 1);
        }

        return $node;
    }
}
