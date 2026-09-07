<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BannerImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BannerController extends Controller
{
    
    public function index(Request $request): JsonResponse
    {
        try {
            $bannerImages = BannerImage::active()
                ->with('city')
                ->ordered()
                ->get()
                ->groupBy('row_position');

            $response = [
                'success' => true,
                'data' => [
                    'first_row' => $bannerImages->get('first', collect())->map(function ($banner) {
                        return $this->formatBannerImage($banner);
                    }),
                    'second_row' => $bannerImages->get('second', collect())->map(function ($banner) {
                        return $this->formatBannerImage($banner);
                    }),
                ],
                'meta' => [
                    'total_first_row' => $bannerImages->get('first', collect())->count(),
                    'total_second_row' => $bannerImages->get('second', collect())->count(),
                    'total_banners' => $bannerImages->flatten()->count(),
                ]
            ];

            return response()->json($response);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve banner images',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    
    public function getByRow(Request $request, string $row): JsonResponse
    {
        try {
            if (!in_array($row, ['first', 'second'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid row position. Must be "first" or "second"'
                ], 400);
            }

            $bannerImages = BannerImage::active()
                ->with('city')
                ->where('row_position', $row)
                ->ordered()
                ->get()
                ->map(function ($banner) {
                    return $this->formatBannerImage($banner);
                });

            return response()->json([
                'success' => true,
                'data' => $bannerImages,
                'meta' => [
                    'row' => $row,
                    'count' => $bannerImages->count()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve banner images for row',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $bannerImage = BannerImage::active()
                ->with('city')
                ->find($id);

            if (!$bannerImage) {
                return response()->json([
                    'success' => false,
                    'message' => 'Banner image not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $this->formatBannerImage($bannerImage)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve banner image',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    
    private function formatBannerImage(BannerImage $banner): array
    {
        $city = $banner->city;

        return [
            'id' => $banner->id,
            'title' => $banner->title ?? '',
            'description' => $banner->description ?? '',
            'image_url' => $banner->getUrl(),
            'image_path' => $banner->image_path,
            'file_name' => $banner->file_name ?? '',
            'file_type' => $banner->file_type ?? '',
            'file_size' => $banner->file_size ?? 0,
            'formatted_size' => $banner->getFormattedSize(),
            'dimensions' => $banner->getDimensions(),
            'width' => $banner->width ?? 0,
            'height' => $banner->height ?? 0,
            'row_position' => $banner->row_position,
            'sort_order' => $banner->sort_order,
            'is_active' => $banner->is_active,
            'city' => $city ? [
                'id' => $city->id,
                'name' => $city->name ?? '',
                'state' => $city->state ?? '',
                'country' => $city->country ?? '',
                'full_name' => collect([$city->name, $city->state, $city->country])
                    ->filter()
                    ->implode(', '),
            ] : null,
            'link' => [
                'url' => $banner->link_url ?? '',
                'text' => $banner->link_text ?? '',
                'has_link' => $banner->hasLink(),
            ],
            'metadata' => $banner->metadata ?? [],
            'created_at' => $banner->created_at,
            'updated_at' => $banner->updated_at,
        ];
    }
}
