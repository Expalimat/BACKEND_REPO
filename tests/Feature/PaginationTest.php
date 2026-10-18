<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaginationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_list_endpoints_support_paged_filtered_responses_and_keep_legacy_array_responses(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->actingAs($admin)
            ->getJson('/api/users?page=1&per_page=1&search=student&filter_field=role&filter_value=Student')
            ->assertOk()
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('per_page', 1)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.username', 'student');

        $this->actingAs($admin)
            ->getJson('/api/users')
            ->assertOk()
            ->assertJsonCount(5);

        $student = User::where('username', 'student')->firstOrFail();
        $this->actingAs($student)
            ->getJson('/api/grades?term=2nd%20Semester%202025-2026&page=1&per_page=1')
            ->assertOk()
            ->assertJsonStructure(['data', 'summary' => ['validatedCount', 'gpa']]);

        $this->actingAs($student)
            ->getJson('/api/payments?page=1&per_page=1')
            ->assertOk()
            ->assertJsonStructure(['data', 'verifiedEnrollmentIds']);
    }
}
