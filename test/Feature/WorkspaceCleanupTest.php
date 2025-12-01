public function test_cleanup_api_preview()
{
    // Buat workspace rollback
    $ws = DochubWorkspace::factory()->create(['name' => 'test-rollback-2025']);
    DochubMergeSession::factory()->create([
        'target_workspace_id' => $ws->id,
        'source_type' => 'rollback',
    ]);
    DochubFile::factory()->count(5)->create(['workspace_id' => $ws->id]);

    $response = $this->getJson('/api/workspace/cleanup-rollback?days=1');
    
    $response->assertOk()
        ->assertJson([
            'success' => true,
            'data' => [
                'candidates_count' => 1,
            ]
        ]);
}