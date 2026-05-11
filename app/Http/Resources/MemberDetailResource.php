<?php

namespace App\Http\Resources;

use App\Models\JoinedCircleCategory;
use Illuminate\Support\Facades\Schema;

class MemberDetailResource extends UserResource
{
    public function toArray($request): array
    {
        $data = parent::toArray($request);

        $data['medal_rank'] = $this->coin_medal_rank;
        $data['title'] = $this->coin_milestone_title;
        $data['meaning_and_vibe'] = $this->coin_milestone_meaning;
        $data['contribution_award_name'] = $this->contribution_award_name;
        $data['contribution_recognition'] = $this->contribution_award_recognition;
        $data['main_business_category_id'] = $this->main_business_category_id !== null ? (int) $this->main_business_category_id : null;
        $data['business_category_id'] = $this->business_category_id !== null ? (int) $this->business_category_id : null;
        $data['main_business_category'] = $this->formatCategory($this->mainBusinessCategory);
        $data['business_category'] = $this->formatCategory($this->businessCategory);
        $data['categories'] = $this->appendRegisteredBusinessCategory(
            $this->resolveJoinedCircleCategories()
        );

        return $data;
    }


    private function appendRegisteredBusinessCategory(array $categories): array
    {
        $mainCategory = $this->formatCategory($this->mainBusinessCategory);
        $businessCategory = $this->formatCategory($this->businessCategory);

        if (! $mainCategory && ! $businessCategory) {
            return $categories;
        }

        $alreadyExists = collect($categories)->contains(function (array $category) use ($mainCategory, $businessCategory): bool {
            $existingMainId = data_get($category, 'main_category.id')
                ?? data_get($category, 'level1_category.id');
            $existingBusinessId = data_get($category, 'business_category.id')
                ?? data_get($category, 'level4_category.id');

            return ($mainCategory === null || (string) $existingMainId === (string) $mainCategory['id'])
                && ($businessCategory === null || (string) $existingBusinessId === (string) $businessCategory['id']);
        });

        if (! $alreadyExists) {
            $categories[] = [
                'main_category' => $mainCategory,
                'business_category' => $businessCategory,
            ];
        }

        return array_values($categories);
    }

    private function formatCategory($category): ?array
    {
        if (! $category) {
            return null;
        }

        return [
            'id' => (int) $category->id,
            'name' => (string) $category->name,
        ];
    }

    private function resolveJoinedCircleCategories(): array
    {
        if (! Schema::hasTable('joined_circle_categories')) {
            return [];
        }

        return JoinedCircleCategory::query()
            ->where('user_id', $this->id)
            ->with([
                'circle:id,name',
                'level1Category:id,name',
                'level2Category:id,name',
                'level3Category:id,name',
                'level4Category:id,name',
            ])
            ->orderByDesc('updated_at')
            ->get()
            ->map(function (JoinedCircleCategory $row): array {
                return [
                    'circle_id' => $row->circle_id,
                    'circle_name' => $row->circle?->name,
                    'level1_category' => $row->level1Category
                        ? ['id' => $row->level1Category->id, 'name' => $row->level1Category->name]
                        : null,
                    'level2_category' => $row->level2Category
                        ? ['id' => $row->level2Category->id, 'name' => $row->level2Category->name]
                        : null,
                    'level3_category' => $row->level3Category
                        ? ['id' => $row->level3Category->id, 'name' => $row->level3Category->name]
                        : null,
                    'level4_category' => $row->level4Category
                        ? ['id' => $row->level4Category->id, 'name' => $row->level4Category->name]
                        : null,
                ];
            })
            ->values()
            ->all();
    }
}
