<?php

namespace Tests\Feature;

use App\Jobs\GenerateSpeech;
use App\Models\Generation;
use App\Models\User;
use App\Models\Voice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AppTest extends TestCase
{
    use RefreshDatabase;

    private array $payload = [
        'text' => 'Bonjour à tous.', 'language' => 'fr', 'voice_id' => null,
        'exaggeration' => 0.5, 'cfg_weight' => 0.5, 'temperature' => 0.8, 'seed' => null,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        config(['voice.engine_url' => 'http://engine.test', 'voice.engine_token' => 'secret']);
    }

    /** Un vrai petit fichier WAV, pour que la détection du type fonctionne. */
    private function wav(): UploadedFile
    {
        $pcm = str_repeat("\0", 16000);
        $header = 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($pcm));

        return $this->upload('voix.wav', $header.$pcm);
    }

    /** Fichier réel sur disque : le type est détecté d'après le contenu, comme en production. */
    private function upload(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, null, null, true);
    }

    public function test_guests_are_sent_to_login(): void
    {
        foreach (['/', '/voices', '/generations'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->post('/generations', $this->payload)->assertRedirect('/login');
    }

    public function test_login_works_and_rejects_bad_password(): void
    {
        $user = User::factory()->create(['password' => 'un-bon-mot-de-passe']);

        $this->post('/login', ['email' => $user->email, 'password' => 'faux'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/login', ['email' => $user->email, 'password' => 'un-bon-mot-de-passe'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_user_create_command(): void
    {
        $this->artisan('user:create', ['email' => 'a@example.com', '--password' => 'court'])->assertFailed();
        $this->artisan('user:create', ['email' => 'a@example.com', '--password' => 'assez-long-123'])->assertSuccessful();
        $this->assertTrue(auth()->attempt(['email' => 'a@example.com', 'password' => 'assez-long-123']));
    }

    public function test_pages_render(): void
    {
        Http::fake(['engine.test/health' => Http::response(['status' => 'ok', 'driver' => 'fake'])]);
        $user = User::factory()->create();
        Generation::factory()->for($user)->create();
        Voice::factory()->for($user)->create();

        $this->actingAs($user)->get('/')->assertOk()->assertSee('Studio');
        $this->actingAs($user)->get('/voices')->assertOk();
        $this->actingAs($user)->get('/generations')->assertOk();
    }

    public function test_creating_a_generation_queues_the_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post('/generations', $this->payload)->assertRedirect();

        $generation = Generation::sole();
        $this->assertSame('pending', $generation->status);
        $this->assertNull($generation->voice_id);
        Queue::assertPushed(GenerateSpeech::class, fn ($job) => $job->generation->is($generation));
    }

    public function test_generation_input_is_validated(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post('/generations', ['text' => '', 'language' => 'es', 'voice_id' => 999, 'exaggeration' => 9] + $this->payload)
            ->assertSessionHasErrors(['text', 'language', 'voice_id', 'exaggeration']);
        $this->actingAs($user)->post('/generations', ['text' => str_repeat('a', 5001)] + $this->payload)
            ->assertSessionHasErrors('text');
        Queue::assertNothingPushed();
    }

    public function test_job_sends_the_voice_sample_and_stores_the_audio(): void
    {
        Http::fake(['engine.test/v1/speech' => Http::response('MP3DATA', 200, ['X-Audio-Duration-Ms' => '1500'])]);
        Storage::put('voices/a.wav', 'SAMPLE');
        $voice = Voice::factory()->create(['sample_path' => 'voices/a.wav']);
        $generation = Generation::factory()->create(['voice_id' => $voice->id, 'voice_name' => $voice->name]);

        GenerateSpeech::dispatchSync($generation);

        $generation->refresh();
        $this->assertSame('completed', $generation->status);
        $this->assertSame(1500, $generation->duration_ms);
        $this->assertSame('MP3DATA', Storage::get($generation->audio_path));
        Http::assertSent(function (Request $request) use ($generation) {
            $body = $request->body();

            return $request->hasHeader('Authorization', 'Bearer secret')
                && str_contains($body, 'SAMPLE') && str_contains($body, $generation->text)
                && ! str_contains($body, 'name="seed"');
        });
    }

    public function test_job_failure_is_recorded(): void
    {
        Http::fake(['engine.test/v1/speech' => Http::response(['detail' => 'Échantillon trop court (3 secondes minimum).'], 422)]);
        $generation = Generation::factory()->create();

        try {
            GenerateSpeech::dispatchSync($generation);
        } catch (\Throwable $e) {
            (new GenerateSpeech($generation))->failed($e);
        }

        $generation->refresh();
        $this->assertSame('failed', $generation->status);
        $this->assertSame('Échantillon trop court (3 secondes minimum).', $generation->error);
    }

    public function test_audio_is_served_only_to_signed_in_users(): void
    {
        Storage::put('generations/x.mp3', 'MP3DATA');
        $generation = Generation::factory()->create(['status' => 'completed', 'audio_path' => 'generations/x.mp3']);
        $url = "/generations/{$generation->id}/audio";

        $this->get($url)->assertRedirect('/login');
        $this->actingAs($generation->user)->get($url)->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
        $this->actingAs($generation->user)->get($url.'?download=1')->assertDownload("voix-{$generation->id}.mp3");

        $pending = Generation::factory()->create();
        $this->actingAs($generation->user)->get("/generations/{$pending->id}/audio")->assertNotFound();
    }

    public function test_deleting_a_generation_removes_its_file(): void
    {
        Storage::put('generations/x.mp3', 'MP3DATA');
        $generation = Generation::factory()->create(['status' => 'completed', 'audio_path' => 'generations/x.mp3']);

        $this->actingAs($generation->user)->delete("/generations/{$generation->id}")->assertRedirect();

        $this->assertModelMissing($generation);
        Storage::assertMissing('generations/x.mp3');
    }

    public function test_voice_requires_consent_and_an_audio_file(): void
    {
        $user = User::factory()->create();
        $base = ['name' => 'Camille', 'language' => 'fr'];

        $this->actingAs($user)->post('/voices', $base + ['sample' => $this->wav()])->assertSessionHasErrors('consent');
        $this->actingAs($user)->post('/voices', $base + ['consent' => true])->assertSessionHasErrors('sample');
        $this->actingAs($user)->post('/voices', $base + ['consent' => true, 'sample' => $this->upload('x.wav', '<?php echo 1;')])
            ->assertSessionHasErrors('sample');
        $this->assertSame(0, Voice::count());
    }

    public function test_voice_can_be_created_played_and_deleted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/voices', ['name' => 'Camille', 'language' => 'fr', 'consent' => true, 'sample' => $this->wav()])
            ->assertSessionHasNoErrors()->assertRedirect();

        $voice = Voice::sole();
        $this->assertNotNull($voice->consent_confirmed_at);
        Storage::assertExists($voice->sample_path);
        $this->actingAs($user)->get("/voices/{$voice->id}/sample")->assertOk();

        $generation = Generation::factory()->create(['voice_id' => $voice->id, 'voice_name' => 'Camille']);
        $this->actingAs($user)->delete("/voices/{$voice->id}")->assertRedirect();

        Storage::assertMissing($voice->sample_path);
        $this->assertNull($generation->refresh()->voice_id);
        $this->assertSame('Camille', $generation->voice_name);
    }
}
