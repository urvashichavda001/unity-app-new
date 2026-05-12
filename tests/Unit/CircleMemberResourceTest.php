<?php

namespace Tests\Unit;

use App\Http\Resources\CircleMemberResource;
use App\Models\CircleCategory;
use App\Models\CircleMember;
use App\Models\City;
use App\Models\User;
use Illuminate\Http\Request;
use Tests\TestCase;

class CircleMemberResourceTest extends TestCase
{
    public function test_it_enriches_user_with_city_and_business_category_relations(): void
    {
        $city = new City([
            'name' => 'Ahmedabad',
        ]);
        $city->id = 'city-123';

        $businessCategory = new CircleCategory([
            'name' => 'Manufacturing & Engineering Circles',
        ]);
        $businessCategory->id = 'category-123';

        $user = new User([
            'display_name' => 'Unity Member',
            'email' => 'member@example.com',
            'city_id' => $city->id,
            'business_category_id' => $businessCategory->id,
            'business_sub_category' => 'Software Development',
        ]);
        $user->id = 'user-123';
        $user->setRelation('city', $city);
        $user->setRelation('businessCategory', $businessCategory);

        $data = $this->resourceUserPayload($user);

        $this->assertSame('city-123', $data['city_id']);
        $this->assertSame('Ahmedabad', $data['city_name']);
        $this->assertSame('Ahmedabad', $data['city']);
        $this->assertSame('category-123', $data['business_category_id']);
        $this->assertSame('Manufacturing & Engineering Circles', $data['business_category_name']);
        $this->assertSame('Manufacturing & Engineering Circles', $data['business_category']);
        $this->assertSame('Software Development', $data['business_sub_category']);
    }

    public function test_it_supports_string_city_and_category_fallbacks(): void
    {
        $user = new User([
            'display_name' => 'Unity Member',
            'email' => 'member@example.com',
            'city_id' => 'city-456',
            'city' => 'Ahmedabad',
            'business_category_id' => 'category-456',
            'business_category' => 'Manufacturing & Engineering Circles',
            'business_sub_category' => 'Software Development',
        ]);
        $user->id = 'user-456';

        $data = $this->resourceUserPayload($user);

        $this->assertSame('city-456', $data['city_id']);
        $this->assertSame('Ahmedabad', $data['city_name']);
        $this->assertSame('Ahmedabad', $data['city']);
        $this->assertSame('category-456', $data['business_category_id']);
        $this->assertSame('Manufacturing & Engineering Circles', $data['business_category_name']);
        $this->assertSame('Manufacturing & Engineering Circles', $data['business_category']);
        $this->assertSame('Software Development', $data['business_sub_category']);
    }

    private function resourceUserPayload(User $user): array
    {
        $member = new CircleMember([
            'circle_id' => 'circle-123',
            'role' => 'member',
            'status' => 'approved',
        ]);
        $member->id = 'member-123';
        $member->setRelation('user', $user);

        $data = (new CircleMemberResource($member))->toArray(Request::create('/'));

        return $data['user'];
    }
}
