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
    public function test_it_enriches_user_with_city_and_business_category_fields(): void
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

        $member = new CircleMember([
            'circle_id' => 'circle-123',
            'role' => 'member',
            'status' => 'approved',
        ]);
        $member->id = 'member-123';
        $member->setRelation('user', $user);

        $data = (new CircleMemberResource($member))->toArray(Request::create('/'));

        $this->assertSame('city-123', $data['user']['city_id']);
        $this->assertSame('Ahmedabad', $data['user']['city_name']);
        $this->assertSame('Ahmedabad', $data['user']['city']);
        $this->assertSame('category-123', $data['user']['business_category_id']);
        $this->assertSame('Manufacturing & Engineering Circles', $data['user']['business_category_name']);
        $this->assertSame('Manufacturing & Engineering Circles', $data['user']['business_category']);
        $this->assertSame('Software Development', $data['user']['business_sub_category']);
    }
}
