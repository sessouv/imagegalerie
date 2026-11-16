<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Generation;
use App\Models\Project;
use App\Models\Transcription;
use App\Models\User;
use App\Models\Voice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ExtrasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        config(['voice.engine_url' => 'http://engine.test', 'voice.engine_token' => 'secret']);
    }

    private function wavBody(): string
    {
        $pcm = str_repeat("\0", 24000);

        return 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16).'data'.pack('V', strlen($pcm)).$pcm;
    }

    private function wavUpload(string $name = 'Réunion du lundi.wav'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($path, $this->wavBody());

        return new UploadedFile($path, $name, null, null, true);
    }

    private function fakeSpeechEngine(): void
    {
        Http::fake([
            'engine.test/v1/speech' => fn () => Http::response($this->wavBody(), 200, ['X-Audio-Duration-Ms' => '500']),
            'engine.test/v1/encode' => fn () => Http::response('MP3DATA'),
        ]);
    }

    // --- Projets et recherche -------------------------------------------------

    public function test_history_can_be_searched_and_filtered_by_project(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/projects', ['name' => ''])->assertSessionHasErrors('name');
        $this->post('/projects', ['name' => 'Formation SEO']);
        $project = Project::sole();

        $a = Generation::factory()->for($user)->create(['text' => 'Bienvenue dans ce module.', 'project_id' => $project->id]);
        $b = Generation::factory()->for($user)->create(['text' => 'Autre chose.', 'title' => 'Pub radio 100%']);
        $c = Generation::factory()->for($user)->create(['text' => 'Encore autre chose.', 'voice_name' => 'Camille']);

        $ids = fn (string $query) => collect($this->get('/generations'.$query)->assertOk()->viewData('page')['props']['generations']['data'])
            ->pluck('id')->all();

        $this->assertSame([$c->id, $b->id, $a->id], $ids(''));
        $this->assertSame([$a->id], $ids('?q=module'));
        $this->assertSame([$b->id], $ids('?q=radio'));
        $this->assertSame([$c->id], $ids('?q=camille'));
        // « % » est cherché tel quel, pas comme un joker
        $this->assertSame([$b->id], $ids('?q=100%25'));
        $this->assertSame([], $ids('?q=%25'.'zzz'));
        $this->assertSame([$a->id], $ids('?project='.$project->id));
        $this->assertSame([$c->id, $b->id], $ids('?project=none'));
        $this->assertSame([], $ids('?project='.$project->id.'&q=radio'));

        $this->get('/generations?project='.$project->id)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('projects.0.generations_count', 1)->where('unfiled', 2)->where('filters.project', (string) $project->id));
    }

    public function test_audio_can_be_moved_between_projects_and_survives_project_deletion(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'name' => 'Podcast']);
        $generation = Generation::factory()->for($user)->create(['title' => 'Épisode 1']);

        $this->actingAs($user)->patch("/generations/{$generation->id}", ['project_id' => $project->id])->assertSessionHasNoErrors();
        $generation->refresh();
        // Ranger un audio ne touche pas à son nom
        $this->assertSame([$project->id, 'Épisode 1'], [$generation->project_id, $generation->title]);
        $this->patch("/generations/{$generation->id}", ['project_id' => 999])->assertSessionHasErrors('project_id');

        $this->put("/projects/{$project->id}", ['name' => 'Podcast 2026']);
        $this->assertSame('Podcast 2026', $project->refresh()->name);

        $this->delete("/projects/{$project->id}")->assertRedirect('/generations');
        $this->assertModelExists($generation);
        $this->assertNull($generation->refresh()->project_id);
    }

    public function test_a_new_audio_can_be_filed_in_a_project_from_the_studio(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'name' => 'Podcast']);

        $this->actingAs($user)->post('/generations', ['text' => 'Bonjour.', 'language' => 'fr', 'project_id' => $project->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($project->id, Generation::sole()->project_id);
    }

    // --- API ------------------------------------------------------------------

    public function test_api_requires_a_valid_key(): void
    {
        $user = User::factory()->create();
        [$token, $plain] = ApiToken::issue($user, 'Site vitrine');

        $this->getJson('/api/v1/voices')->assertStatus(401);
        $this->getJson('/api/v1/voices', ['Authorization' => 'Bearer faux'])->assertStatus(401);
        // La session du site ne donne pas accès à l'API
        $this->actingAs($user)->getJson('/api/v1/voices')->assertStatus(401);

        $this->getJson('/api/v1/voices', ['Authorization' => 'Bearer '.$plain])->assertOk();
        $this->assertNotNull($token->refresh()->last_used_at);
        $this->assertDatabaseMissing('api_tokens', ['token_hash' => $plain]);

        $token->delete();
        $this->getJson('/api/v1/voices', ['Authorization' => 'Bearer '.$plain])->assertStatus(401);
    }

    public function test_api_generates_speech_end_to_end(): void
    {
        $this->fakeSpeechEngine();
        $user = User::factory()->create();
        Storage::put('voices/c.wav', 'SAMPLE');
        $voice = Voice::factory()->for($user)->create(['name' => 'Camille', 'sample_path' => 'voices/c.wav']);
        $auth = ['Authorization' => 'Bearer '.ApiToken::issue($user, 'Test')[1]];

        $this->getJson('/api/v1/voices', $auth)->assertOk()->assertJsonPath('data.0.name', 'Camille')
            ->assertJsonMissingPath('data.0.sample_path');

        $this->postJson('/api/v1/generations', ['text' => '', 'language' => 'xx'], $auth)
            ->assertStatus(422)->assertJsonValidationErrors(['text', 'language']);

        $created = $this->postJson('/api/v1/generations', [
            'text' => "Bonjour.\nAu revoir.", 'language' => 'fr', 'voice_id' => $voice->id, 'title' => 'Accueil',
        ], $auth)->assertStatus(202)->assertJsonPath('data.passages_total', 2);

        $id = $created->json('data.id');
        $shown = $this->getJson("/api/v1/generations/{$id}", $auth)->assertOk()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.passages_done', 2)
            ->assertJsonPath('data.voice', 'Camille')->assertJsonPath('data.duration_ms', 1650);

        $this->assertStringEndsWith("/api/v1/generations/{$id}/audio", $shown->json('data.audio_url'));
        $this->get($shown->json('data.audio_url'), $auth)->assertDownload('accueil.mp3');
        $this->get($shown->json('data.wav_url'), $auth)->assertDownload('accueil.wav');
        $this->get("/api/v1/generations/{$id}/audio")->assertStatus(401);

        $this->deleteJson("/api/v1/generations/{$id}", [], $auth)->assertStatus(204);
        $this->getJson("/api/v1/generations/{$id}", $auth)->assertStatus(404);
    }

    public function test_api_audio_is_unavailable_until_the_generation_is_done(): void
    {
        $generation = Generation::factory()->create(['status' => 'processing']);
        $auth = ['Authorization' => 'Bearer '.ApiToken::issue($generation->user, 'Test')[1]];

        $this->getJson("/api/v1/generations/{$generation->id}", $auth)->assertOk()->assertJsonPath('data.audio_url', null);
        $this->getJson("/api/v1/generations/{$generation->id}/audio", $auth)->assertStatus(404);
    }

    public function test_api_keys_are_shown_once_and_can_be_revoked(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/api-keys', ['name' => ''])->assertSessionHasErrors('name');
        $this->followingRedirects()->from('/api-keys')->post('/api-keys', ['name' => 'Site WordPress'])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('newToken', fn ($plain) => str_starts_with($plain, 'timbre_') && ApiToken::findByPlain($plain) !== null)
                ->where('tokens.0.name', 'Site WordPress')->missing('tokens.0.token_hash'));

        // Au rechargement, la clé n'est plus affichée
        $this->get('/api-keys')->assertInertia(fn (AssertableInertia $page) => $page->where('newToken', null));

        $this->delete('/api-keys/'.ApiToken::sole()->id);
        $this->assertSame(0, ApiToken::count());
    }

    // --- Transcription --------------------------------------------------------

    public function test_transcription_is_sent_to_the_engine_then_polled_until_done(): void
    {
        $segments = [['start' => 0, 'end' => 2.5, 'text' => 'Bonjour à tous.'], ['start' => 2.5, 'end' => 3661.2, 'text' => 'Merci.']];
        Http::fake([
            'engine.test/v1/transcriptions' => Http::response(['id' => 'job1', 'status' => 'pending'], 202),
            'engine.test/v1/transcriptions/job1' => Http::sequence()
                ->push(['status' => 'processing', 'progress' => 40])
                ->push(['status' => 'completed', 'progress' => 100, 'language' => 'fr', 'duration_ms' => 3661200,
                    'text' => 'Bonjour à tous. Merci.', 'segments' => $segments]),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)->post('/transcriptions', ['file' => $this->wavUpload(), 'language' => 'fr'])->assertSessionHasNoErrors();

        $transcription = Transcription::sole();
        $this->assertSame(['completed', 100, 'fr', 'Bonjour à tous. Merci.'],
            [$transcription->status, $transcription->progress, $transcription->detected_language, $transcription->text]);
        $this->assertSame('Réunion du lundi.wav', $transcription->name);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/transcriptions')
            && $r->hasHeader('Authorization', 'Bearer secret')
            && collect($r->data())->contains(fn ($part) => ($part['name'] ?? null) === 'language' && $part['contents'] === 'fr'));

        $this->get('/transcriptions')->assertOk();
        $txt = $this->get("/transcriptions/{$transcription->id}/download/txt")->assertOk();
        $this->assertSame("Bonjour à tous. Merci.\n", $txt->getContent());
        $this->assertStringContainsString('reunion-du-lundi.txt', $txt->headers->get('Content-Disposition'));
        $this->assertSame(
            "1\n00:00:00,000 --> 00:00:02,500\nBonjour à tous.\n\n2\n00:00:02,500 --> 01:01:01,200\nMerci.\n",
            $this->get("/transcriptions/{$transcription->id}/download/srt")->getContent(),
        );
        $this->get("/transcriptions/{$transcription->id}/download/exe")->assertNotFound();

        $this->delete("/transcriptions/{$transcription->id}");
        $this->assertSame([], Storage::allFiles('transcriptions'));
    }

    public function test_transcription_restarts_when_the_engine_forgot_the_job(): void
    {
        Http::fake([
            'engine.test/v1/transcriptions' => Http::sequence()->push(['id' => 'old'], 202)->push(['id' => 'new'], 202),
            'engine.test/v1/transcriptions/old' => Http::response(['detail' => 'inconnue'], 404),
            'engine.test/v1/transcriptions/new' => Http::response(['status' => 'completed', 'text' => 'Ok.', 'segments' => []]),
        ]);

        $this->actingAs(User::factory()->create())->post('/transcriptions', ['file' => $this->wavUpload()]);

        $this->assertSame(['completed', 'Ok.'], [Transcription::sole()->status, Transcription::sole()->text]);
    }

    public function test_transcription_failure_is_reported_and_can_be_retried(): void
    {
        Http::fake([
            'engine.test/v1/transcriptions' => Http::sequence()
                ->push(['detail' => 'Fichier illisible : ce n\'est ni un audio ni une vidéo reconnue.'], 422)
                ->push(['id' => 'job2'], 202),
            'engine.test/v1/transcriptions/job2' => Http::response(['status' => 'completed', 'text' => 'Ok.', 'segments' => []]),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)->post('/transcriptions', [])->assertSessionHasErrors('file');
        $this->post('/transcriptions', ['file' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('file');
        $this->assertSame(0, Transcription::count());

        $this->post('/transcriptions', ['file' => $this->wavUpload()]);
        $transcription = Transcription::sole();
        $this->assertSame('failed', $transcription->status);
        $this->assertStringStartsWith('Fichier illisible', $transcription->error);
        $this->get("/transcriptions/{$transcription->id}/download/txt")->assertNotFound();

        $this->post("/transcriptions/{$transcription->id}/retry")->assertRedirect();
        $this->assertSame('completed', $transcription->refresh()->status);
        $this->post("/transcriptions/{$transcription->id}/retry")->assertStatus(409);
    }

    public function test_api_transcribes_a_file(): void
    {
        Http::fake([
            'engine.test/v1/transcriptions' => Http::response(['id' => 'job1'], 202),
            'engine.test/v1/transcriptions/job1' => Http::response(['status' => 'completed', 'text' => 'Bonjour.',
                'language' => 'fr', 'segments' => [['start' => 0, 'end' => 1, 'text' => 'Bonjour.']]]),
        ]);
        $auth = ['Authorization' => 'Bearer '.ApiToken::issue(User::factory()->create(), 'Test')[1]];

        $this->post('/api/v1/transcriptions', [], $auth + ['Accept' => 'application/json'])->assertStatus(422);
        $id = $this->post('/api/v1/transcriptions', ['file' => $this->wavUpload()], $auth + ['Accept' => 'application/json'])
            ->assertStatus(202)->json('data.id');

        $this->getJson("/api/v1/transcriptions/{$id}", $auth)->assertOk()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.text', 'Bonjour.')
            ->assertJsonPath('data.segments.0.end', 1);
        $this->deleteJson("/api/v1/transcriptions/{$id}", [], $auth)->assertStatus(204);
    }
}
