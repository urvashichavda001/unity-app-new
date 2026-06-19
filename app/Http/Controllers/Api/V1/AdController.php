<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Api\V1\Ads\IndexAdRequest;
use App\Http\Resources\V1\AdResource;
use App\Models\Ad;
use App\Services\AdFeedService;
use Illuminate\Http\Request;

class AdController extends BaseApiController
{
    public function myAds(Request $request)
    {
        $ads = Ad::query()
            ->where('created_by', $request->user()->id)
            ->latest()
            ->get()
            ->map(fn (Ad $ad): array => [
                'id' => $ad->id,
                'user_id' => $ad->created_by,
                'title' => $ad->title,
                'description' => $ad->description,
                'image_url' => $ad->image_url,
                'status' => $ad->is_active ? 'active' : 'inactive',
                'created_at' => $ad->created_at,
                'updated_at' => $ad->updated_at,
            ]);

        return response()->json([
            'success' => true,
            'status' => true,
            'message' => $ads->isEmpty() ? 'No ads found.' : 'Ads fetched successfully.',
            'data' => $ads->values(),
            'meta' => null,
        ]);
    }

    public function index(IndexAdRequest $request)
    {
        $filters = $request->validated();
        $perPage = (int) ($filters['per_page'] ?? 15);

        $ads = Ad::query()
            ->currentlyVisible()
            ->when(! empty($filters['placement']), fn ($query) => $query->where('placement', $filters['placement']))
            ->when(! empty($filters['page_name']), fn ($query) => $query->where('page_name', $filters['page_name']))
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->appends($request->query());

        return $this->success([
            'items' => AdResource::collection($ads->getCollection()),
            'pagination' => [
                'current_page' => $ads->currentPage(),
                'last_page' => $ads->lastPage(),
                'per_page' => $ads->perPage(),
                'total' => $ads->total(),
            ],
        ]);
    }

    public function show(string $id)
    {
        $ad = Ad::query()->currentlyVisible()->find($id);

        if (! $ad) {
            return $this->error('Ad not found', 404);
        }

        return $this->success(AdResource::make($ad));
    }

    public function timeline(AdFeedService $adFeedService)
    {
        $ads = $adFeedService->timelineAds();

        return $this->success(AdResource::collection($ads));
    }
}
