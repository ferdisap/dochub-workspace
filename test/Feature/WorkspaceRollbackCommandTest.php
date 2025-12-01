<!-- public function test_rollback_creates_new_workspace()
{
    $workspace = DochubWorkspace::factory()->create();
    $merge = DochubMerge::factory()->for($workspace)->create();
    DochubFile::factory()->count(5)->for($merge)->create();

    $this->artisan('workspace:rollback', [
        'workspace-id' => $workspace->id,
        'merge-id' => $merge->id,
        '--name' => 'test-rollback',
    ])->assertExitCode(0);

    $this->assertDatabaseHas('dochub_workspaces', [
        'name' => 'test-rollback',
        'owner_id' => $workspace->owner_id,
    ]);

    $newWorkspace = DochubWorkspace::where('name', 'test-rollback')->first();
    $this->assertDatabaseCount('dochub_files', 5, [
        'workspace_id' => $newWorkspace->id,
    ]);
} -->