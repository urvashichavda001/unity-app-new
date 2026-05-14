<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ProfileMatchService
{
    private const WEIGHTS = [
        'city' => 15,
        'business_category' => 20,
        'business_sub_category' => 15,
        'business_type' => 10,
        'company_type' => 5,
        'target_business_categories' => 10,
        'target_regions' => 10,
        'experience_years' => 5,
        'same_circle' => 10,
    ];

    /**
     * @var array<string, array<int, string>>
     */
    private array $circleIdsByUserId = [];

    public function calculate(User $authUser, User $member): array
    {
        if ((string) $authUser->id === (string) $member->id) {
            return [
                'score' => 0,
                'percentage' => 0,
                'level' => 'Self',
                'matched_fields' => [],
            ];
        }

        $score = 0;
        $matchedFields = [];

        if ($this->cityMatches($authUser, $member)) {
            $score += self::WEIGHTS['city'];
            $matchedFields[] = 'city';
        }

        if ($this->sameScalar($authUser->business_category_id, $member->business_category_id)) {
            $score += self::WEIGHTS['business_category'];
            $matchedFields[] = 'business_category';
        }

        if ($this->textEquals($authUser->business_sub_category, $member->business_sub_category)) {
            $score += self::WEIGHTS['business_sub_category'];
            $matchedFields[] = 'business_sub_category';
        }

        if ($this->textSimilar($authUser->business_type, $member->business_type)) {
            $score += self::WEIGHTS['business_type'];
            $matchedFields[] = 'business_type';
        }

        if ($this->textEquals($authUser->company_type, $member->company_type)) {
            $score += self::WEIGHTS['company_type'];
            $matchedFields[] = 'company_type';
        }

        if ($this->hasCommonValue($authUser->target_business_categories, $member->target_business_categories)) {
            $score += self::WEIGHTS['target_business_categories'];
            $matchedFields[] = 'target_business_categories';
        }

        if ($this->hasCommonValue($authUser->target_regions, $member->target_regions)) {
            $score += self::WEIGHTS['target_regions'];
            $matchedFields[] = 'target_regions';
        }

        if ($this->experienceYearsAreSimilar($authUser->experience_years, $member->experience_years)) {
            $score += self::WEIGHTS['experience_years'];
            $matchedFields[] = 'experience_years';
        }

        if ($this->hasCommonCircle($authUser, $member)) {
            $score += self::WEIGHTS['same_circle'];
            $matchedFields[] = 'same_circle';
        }

        $score = min($score, 100);

        return [
            'score' => $score,
            'percentage' => $score,
            'level' => $this->getMatchLevel($score),
            'matched_fields' => $matchedFields,
        ];
    }

    public function getMatchLevel(int $score): string
    {
        return match (true) {
            $score >= 80 => 'Excellent Match',
            $score >= 60 => 'High Match',
            $score >= 40 => 'Medium Match',
            $score >= 20 => 'Low Match',
            default => 'Very Low Match',
        };
    }

    /**
     * @return array<int, string>
     */
    public function normalizeArrayField($value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === '') {
                return [];
            }

            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = is_array($decoded) ? $decoded : [$decoded];
            } else {
                $value = preg_split('/[,|;]+/', $trimmed) ?: [];
            }
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->flatMap(fn ($item): array => $this->flattenArrayValue($item))
            ->map(fn ($item): string => $this->normalizeText($item))
            ->filter(fn (string $item): bool => $item !== '')
            ->unique()
            ->values()
            ->all();
    }

    public function textEquals($a, $b): bool
    {
        $a = $this->normalizeText($a);
        $b = $this->normalizeText($b);

        return $a !== '' && $b !== '' && $a === $b;
    }

    private function cityMatches(User $authUser, User $member): bool
    {
        if ($this->sameScalar($authUser->city_id, $member->city_id)) {
            return true;
        }

        return $this->textEquals($this->cityName($authUser), $this->cityName($member));
    }

    private function cityName(User $user): mixed
    {
        if ($user->relationLoaded('city') && $user->city) {
            return $user->city->name ?? null;
        }

        $city = $user->getAttribute('city');

        if (is_array($city)) {
            return $city['name'] ?? null;
        }

        return $city;
    }

    private function sameScalar($a, $b): bool
    {
        if (blank($a) || blank($b)) {
            return false;
        }

        return (string) $a === (string) $b;
    }

    private function textSimilar($a, $b): bool
    {
        if ($this->textEquals($a, $b)) {
            return true;
        }

        $a = $this->normalizeText($a);
        $b = $this->normalizeText($b);

        return $a !== '' && $b !== '' && (Str::contains($a, $b) || Str::contains($b, $a));
    }

    private function hasCommonValue($a, $b): bool
    {
        $aValues = $this->normalizeArrayField($a);
        $bValues = $this->normalizeArrayField($b);

        if ($aValues === [] || $bValues === []) {
            return false;
        }

        return array_intersect($aValues, $bValues) !== [];
    }

    private function experienceYearsAreSimilar($a, $b): bool
    {
        if (! is_numeric($a) || ! is_numeric($b)) {
            return false;
        }

        return abs((float) $a - (float) $b) <= 3;
    }

    private function hasCommonCircle(User $authUser, User $member): bool
    {
        $authCircleIds = $this->circleIdsFor($authUser);
        $memberCircleIds = $this->circleIdsFor($member);

        if ($authCircleIds === [] || $memberCircleIds === []) {
            return false;
        }

        return array_intersect($authCircleIds, $memberCircleIds) !== [];
    }

    /**
     * @return array<int, string>
     */
    private function circleIdsFor(User $user): array
    {
        $userId = (string) $user->id;

        if (array_key_exists($userId, $this->circleIdsByUserId)) {
            return $this->circleIdsByUserId[$userId];
        }

        if ($user->relationLoaded('circleMemberships')) {
            $memberships = $user->circleMemberships;
        } else {
            $query = $user->circleMemberships();

            if (Schema::hasColumn('circle_members', 'deleted_at')) {
                $query->whereNull('deleted_at');
            }

            if (Schema::hasColumn('circle_members', 'left_at')) {
                $query->whereNull('left_at');
            }

            $memberships = $query->get(['circle_id']);
        }

        return $this->circleIdsByUserId[$userId] = collect($memberships)
            ->pluck('circle_id')
            ->filter()
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, mixed>
     */
    private function flattenArrayValue($value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            if (array_key_exists('id', $value)) {
                return [$value['id']];
            }

            if (array_key_exists('name', $value)) {
                return [$value['name']];
            }

            return collect($value)
                ->flatMap(fn ($nested): array => $this->flattenArrayValue($nested))
                ->all();
        }

        return [$value];
    }

    private function normalizeText($value): string
    {
        if ($value === null) {
            return '';
        }

        return Str::of((string) $value)
            ->squish()
            ->lower()
            ->toString();
    }
}
