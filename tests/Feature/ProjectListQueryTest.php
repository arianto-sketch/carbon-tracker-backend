<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCarbonData;
use Tests\TestCase;

class ProjectListQueryTest extends TestCase
{
    use BuildsCarbonData, RefreshDatabase;

    private function queriesFor(User $user): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/projects')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_current_user_role_does_not_add_a_query_per_project(): void
    {
        $pm = User::factory()->create();
        $this->makeProject($pm, 'P-1');
        $one = $this->queriesFor($pm);

        foreach (range(2, 6) as $i) {
            $this->makeProject($pm, "P-{$i}");
        }

        $this->assertSame($one, $this->queriesFor($pm));
    }

    public function test_current_user_role_reflects_membership(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $project = $this->makeProject($owner, 'P-1');
        $this->addMember($project, $member, 'viewer');

        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/projects')->assertJsonPath('data.0.current_user_role', 'owner');
        $this->actingAs($member, 'sanctum')->getJson('/api/v1/projects')->assertJsonPath('data.0.current_user_role', 'viewer');
        $this->actingAs(User::factory()->admin()->create(), 'sanctum')->getJson('/api/v1/projects')->assertJsonPath('data.0.current_user_role', null);
    }
}
