<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\ProfileMatchService;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ProfileMatchServiceTest extends TestCase
{
    public function test_calculate_scores_matching_profile_fields(): void
    {
        $authUser = new User([
            'id' => 'auth-user-id',
            'city' => 'Mumbai',
            'business_category_id' => 'category-1',
            'business_sub_category' => 'FinTech',
            'business_type' => 'Software Services',
            'company_type' => 'Private Limited',
            'target_business_categories' => ['Retail', 'Healthcare'],
            'target_regions' => ['West India'],
            'experience_years' => 10,
        ]);
        $authUser->setRelation('circleMemberships', new Collection([(object) ['circle_id' => 'circle-1']]));

        $member = new User([
            'id' => 'member-user-id',
            'city' => ' mumbai ',
            'business_category_id' => 'category-1',
            'business_sub_category' => 'fintech',
            'business_type' => 'Software',
            'company_type' => 'private limited',
            'target_business_categories' => '["Healthcare","Education"]',
            'target_regions' => 'North India, West India',
            'experience_years' => 12,
        ]);
        $member->setRelation('circleMemberships', new Collection([(object) ['circle_id' => 'circle-1']]));

        $match = (new ProfileMatchService())->calculate($authUser, $member);

        $this->assertSame(100, $match['score']);
        $this->assertSame(100, $match['percentage']);
        $this->assertSame('Excellent Match', $match['level']);
        $this->assertSame([
            'city',
            'business_category',
            'business_sub_category',
            'business_type',
            'company_type',
            'target_business_categories',
            'target_regions',
            'experience_years',
            'same_circle',
        ], $match['matched_fields']);
    }

    public function test_normalize_array_field_handles_malformed_json_and_empty_values(): void
    {
        $service = new ProfileMatchService();

        $this->assertSame(['healthcare', 'retail'], $service->normalizeArrayField('Healthcare, Retail'));
        $this->assertSame(['[not-json'], $service->normalizeArrayField('[not-json'));
        $this->assertSame([], $service->normalizeArrayField(null));
    }

    public function test_text_equals_is_case_insensitive_and_trimmed(): void
    {
        $service = new ProfileMatchService();

        $this->assertTrue($service->textEquals('  Private Limited ', 'private limited'));
        $this->assertFalse($service->textEquals('', 'private limited'));
    }
}
