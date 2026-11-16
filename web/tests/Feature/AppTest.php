<?php

namespace Tests\Feature;

use App\Jobs\GenerateSpeech;
use App\Models\Generation;
use App\Models\Pronunciation;
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

    /** Réponse du moteur pour un passage : un WAV d'une demi-seconde. */
    private function wavBody(): string
    {
        $pcm = str_repeat("\0", 24000);

        return 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16).'data'.pack('V', strlen($pcm)).$pcm;
    }

    private function fakeEngine(array $speechResponses = []): void
    {
        $ok = Http::response($this->wavBody(), 200, ['X-Audio-Duration-Ms' => '500']);
        $sequence = Http::sequence($speechResponses)->whenEmpty($ok);

        Http::fake([
            'engine.test/v1/speech' => $sequence,
            'engine.test/v1/encode' => fn () => Http::response('MP3DATA'),
        ]);
    }

    private function speechCalls(): int
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/v1/speech'))->count();
    }

    public function test_creating_a_generation_splits_the_text_and_queues_the_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post('/generations', ['text' => "Un titre\nPremière phrase. Deuxième phrase."] + $this->payload)
            ->assertRedirect();

        $generation = Generation::sole();
        $this->assertSame('pending', $generation->status);
        $this->assertSame(['Un titre', 'Première phrase. Deuxième phrase.'], $generation->segments->pluck('text')->all());
        Queue::assertPushed(GenerateSpeech::class, 1);
    }

    public function test_generation_input_is_validated(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post('/generations', ['text' => '', 'language' => 'es', 'voice_id' => 999, 'exaggeration' => 9] + $this->payload)
            ->assertSessionHasErrors(['text', 'language', 'voice_id', 'exaggeration']);
        $this->actingAs($user)->post('/generations', ['text' => str_repeat('a', 100001)] + $this->payload)
            ->assertSessionHasErrors('text');
        $this->actingAs($user)->post('/generations', ['text' => " \n "] + $this->payload)->assertSessionHasErrors('text');
        Queue::assertNothingPushed();
    }

    public function test_long_text_is_generated_passage_by_passage_and_assembled(): void
    {
        $this->fakeEngine();
        Storage::put('voices/a.wav', 'SAMPLE');
        $voice = Voice::factory()->create(['sample_path' => 'voices/a.wav']);
        $text = implode(' ', array_fill(0, 30, 'Voici une phrase de longueur tout à fait ordinaire.'))."\nEt un second paragraphe.";

        $this->actingAs($voice->user)->post('/generations', ['text' => $text, 'voice_id' => $voice->id] + $this->payload);

        $generation = Generation::withProgress()->sole();
        $count = $generation->segments->count();
        $this->assertGreaterThan(5, $count);
        $this->assertTrue($generation->segments->every(fn ($s) => mb_strlen($s->text) <= 280 && $s->status === 'completed'));
        $this->assertSame('completed', $generation->status);
        $this->assertSame($count, $this->speechCalls());
        $this->assertSame('MP3DATA', Storage::get($generation->audio_path));
        // 500 ms par passage, 250 ms entre deux passages, 650 ms entre deux paragraphes
        $this->assertSame($count * 500 + ($count - 2) * 250 + 650, $generation->duration_ms);
        $this->assertSame($count, $generation->present()['segments_done']);
        Storage::assertMissing($generation->directory().'/assemblage.wav');
        Storage::assertExists($generation->wav_path);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/speech')
            && $r->hasHeader('Authorization', 'Bearer secret')
            && str_contains($r->body(), 'SAMPLE') && ! str_contains($r->body(), 'name="seed"'));
    }

    public function test_a_failed_passage_stops_the_generation_and_retry_resumes_it(): void
    {
        $ok = Http::response($this->wavBody(), 200, ['X-Audio-Duration-Ms' => '500']);
        $this->fakeEngine([$ok, Http::response(['detail' => 'Panne du moteur.'], 500)]);
        $user = User::factory()->create();

        $this->actingAs($user)->post('/generations', ['text' => "Un.\nDeux.\nTrois."] + $this->payload);

        $generation = Generation::sole();
        $this->assertSame('failed', $generation->status);
        $this->assertSame('Panne du moteur.', $generation->error);
        $this->assertSame(['completed', 'failed', 'pending'], $generation->segments->pluck('status')->all());
        $this->assertSame(2, $this->speechCalls());

        $this->actingAs($user)->post("/generations/{$generation->id}/retry")->assertRedirect();

        $generation->refresh();
        $this->assertSame('completed', $generation->status);
        $this->assertNull($generation->error);
        // Le premier passage, déjà prêt, n'a pas été refait
        $this->assertSame(4, $this->speechCalls());
        $this->actingAs($user)->post("/generations/{$generation->id}/retry")->assertStatus(409);
    }

    public function test_one_passage_can_be_corrected_and_regenerated(): void
    {
        $this->fakeEngine();
        $user = User::factory()->create();
        $this->actingAs($user)->post('/generations', ['text' => "Un.\nDeu.\nTrois.", 'seed' => 7] + $this->payload);
        $generation = Generation::sole();
        [$first, $second] = $generation->segments->all();
        $oldAudio = $generation->audio_path;

        $url = "/generations/{$generation->id}/segments/{$second->id}/regenerate";
        $this->actingAs($user)->postJson($url, ['text' => ''])->assertStatus(422);
        $this->actingAs($user)->postJson($url, ['text' => 'Deux.'])->assertOk()
            ->assertJsonPath('segments.1.text', 'Deux.')->assertJsonPath('segments.1.status', 'completed');

        $this->assertSame(4, $this->speechCalls());
        $this->assertSame($first->audio_path, $first->fresh()->audio_path);
        $this->assertNotSame($second->audio_path, $second->fresh()->audio_path);
        Storage::assertMissing($second->audio_path);
        $generation->refresh();
        $this->assertSame("Un.\nDeux.\nTrois.", $generation->text);
        $this->assertSame('completed', $generation->status);
        $this->assertNotSame($oldAudio, $generation->audio_path);
        Storage::assertMissing($oldAudio);
        // Graine fixe : le nouvel essai en utilise une autre, sinon le rendu serait identique
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/speech') && str_contains($r->body(), 'Deux.') && preg_match('/name="seed"\s+8\s/', $r->body()));

        $this->actingAs($user)->getJson("/generations/{$generation->id}/segments")->assertOk()->assertJsonCount(3, 'segments');
        $this->actingAs($user)->get("/generations/{$generation->id}/segments/{$second->id}/audio")->assertOk();

        $other = Generation::factory()->create();
        $this->actingAs($user)->get("/generations/{$other->id}/segments/{$second->id}/audio")->assertNotFound();
        $this->actingAs($user)->postJson("/generations/{$other->id}/segments/{$second->id}/regenerate", ['text' => 'x'])->assertNotFound();
    }

    public function test_interrupted_generations_are_resumed(): void
    {
        $this->fakeEngine();
        $generation = Generation::factory()->create(['status' => 'processing']);
        $generation->segments()->createMany([
            ['position' => 0, 'text' => 'Un.', 'status' => 'processing'],
            ['position' => 1, 'text' => 'Deux.', 'ends_paragraph' => true],
        ]);
        $done = Generation::factory()->create(['status' => 'failed']);

        $this->artisan('generations:resume')->assertSuccessful();

        $this->assertSame('completed', $generation->refresh()->status);
        $this->assertSame('failed', $done->refresh()->status);
    }

    public function test_deleting_a_generation_removes_all_its_files(): void
    {
        $this->fakeEngine();
        $user = User::factory()->create();
        $this->actingAs($user)->post('/generations', ['text' => "Un.\nDeux."] + $this->payload);
        $generation = Generation::sole();
        $this->assertCount(4, Storage::allFiles($generation->directory()));

        $this->actingAs($user)->delete("/generations/{$generation->id}")->assertRedirect();

        $this->assertModelMissing($generation);
        $this->assertSame([], Storage::allFiles('generations'));
        $this->assertDatabaseCount('generation_segments', 0);
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

    public function test_dictionary_changes_what_is_spoken_but_not_the_text(): void
    {
        $this->fakeEngine();
        $user = User::factory()->create();
        $post = fn (array $entry) => $this->actingAs($user)->post('/pronunciations', $entry);

        $post(['term' => 'Qiryna', 'replacement' => 'Kirina'])->assertSessionHasNoErrors();
        $post(['term' => 'Web', 'replacement' => 'ouèbe'])->assertSessionHasNoErrors();
        $post(['term' => 'Le Web Artisan', 'replacement' => 'le ouèbe artisan'])->assertSessionHasNoErrors();
        $post(['term' => 'SEO', 'replacement' => 'S E O', 'case_sensitive' => true])->assertSessionHasNoErrors();
        $post(['term' => 'live', 'replacement' => 'laïve', 'language' => 'fr'])->assertSessionHasNoErrors();
        $post(['term' => 'live', 'replacement' => 'liv', 'language' => 'en'])->assertSessionHasNoErrors();
        $post(['term' => 'qiryna', 'replacement' => 'x'])->assertSessionHasErrors('term');
        $post(['term' => '', 'replacement' => ''])->assertSessionHasErrors(['term', 'replacement']);

        $text = 'QIRYNA et Le Web Artisan : le SEO, pas le seo, en live sur le webinaire.';
        $this->actingAs($user)->post('/generations', ['text' => $text] + $this->payload);

        $this->assertSame($text, Generation::sole()->segments->sole()->text);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/speech')
            && str_contains($r->body(), 'Kirina et le ouèbe artisan : le S E O, pas le seo, en laïve sur le webinaire.'));
    }

    public function test_dictionary_entries_can_be_updated_and_deleted(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/pronunciations', ['term' => 'API', 'replacement' => 'a p i']);
        $entry = Pronunciation::sole();

        $this->get('/pronunciations')->assertOk();
        $this->put("/pronunciations/{$entry->id}", ['term' => 'API', 'replacement' => 'A.P.I.', 'language' => 'fr'])->assertSessionHasNoErrors();
        $this->assertSame(['A.P.I.', 'fr'], [$entry->refresh()->replacement, $entry->language]);
        $this->delete("/pronunciations/{$entry->id}");
        $this->assertModelMissing($entry);
    }

    public function test_voice_sample_is_cleaned_on_creation_and_used_for_generation(): void
    {
        $this->fakeEngine();
        Http::fake(['engine.test/v1/samples/clean' => Http::response('CLEANWAV')]);
        $user = User::factory()->create();

        $this->actingAs($user)->post('/voices', ['name' => 'Camille', 'language' => 'fr', 'consent' => true, 'clean' => true, 'sample' => $this->wav()])
            ->assertSessionHasNoErrors();

        $voice = Voice::sole();
        $this->assertTrue($voice->usesCleaned());
        $this->assertSame('CLEANWAV', Storage::get($voice->cleaned_path));
        Storage::assertExists($voice->sample_path);
        $this->assertSame('CLEANWAV', $this->get("/voices/{$voice->id}/sample")->assertOk()->getFile()->getContent());
        $this->assertNotSame('CLEANWAV', $this->get("/voices/{$voice->id}/sample?version=original")->getFile()->getContent());

        $this->post('/generations', ['text' => 'Bonjour.', 'voice_id' => $voice->id] + $this->payload);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/speech') && str_contains($r->body(), 'CLEANWAV'));

        // Retour à l'échantillon d'origine, puis de nouveau au nettoyé sans rappeler le moteur
        $this->put("/voices/{$voice->id}/cleaning", ['enabled' => false])->assertSessionHasNoErrors();
        $this->assertFalse($voice->refresh()->usesCleaned());
        $this->put("/voices/{$voice->id}/cleaning", ['enabled' => true]);
        $this->assertTrue($voice->refresh()->usesCleaned());
        $this->assertCount(1, Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/v1/samples/clean')));

        $this->delete("/voices/{$voice->id}");
        $this->assertSame([], Storage::allFiles('voices'));
    }

    public function test_cleaning_failure_creates_nothing_and_existing_voice_can_be_cleaned_later(): void
    {
        Http::fake(['engine.test/v1/samples/clean' => Http::sequence()
            ->push(['detail' => 'Il reste moins de 3 secondes de voix une fois les silences retirés.'], 422)
            ->push('CLEANWAV')]);
        $user = User::factory()->create();
        $form = ['name' => 'Camille', 'language' => 'fr', 'consent' => true, 'sample' => $this->wav()];

        $this->actingAs($user)->post('/voices', $form + ['clean' => true])->assertSessionHasErrors('sample');
        $this->assertSame(0, Voice::count());
        $this->assertSame([], Storage::allFiles('voices'));

        $this->post('/voices', ['sample' => $this->wav()] + $form)->assertSessionHasNoErrors();
        $voice = Voice::sole();
        $this->assertFalse($voice->usesCleaned());

        $this->put("/voices/{$voice->id}/cleaning", ['enabled' => true])->assertSessionHasNoErrors();
        $this->assertTrue($voice->refresh()->usesCleaned());
    }

    public function test_favourite_settings_can_be_saved_and_cleared_for_a_voice(): void
    {
        $voice = Voice::factory()->create();
        $url = "/voices/{$voice->id}/settings";

        $this->actingAs($voice->user)->put($url, ['settings' => ['exaggeration' => 9, 'cfg_weight' => 0.3, 'temperature' => 0.7]])
            ->assertSessionHasErrors('settings.exaggeration');
        $this->put($url, ['settings' => ['exaggeration' => 0.7]])->assertSessionHasErrors('settings.cfg_weight');

        $this->put($url, ['settings' => ['exaggeration' => '0.7', 'cfg_weight' => 0.3, 'temperature' => 0.7, 'seed' => 4]])->assertSessionHasNoErrors();
        $this->assertSame(['exaggeration' => 0.7, 'cfg_weight' => 0.3, 'temperature' => 0.7], $voice->refresh()->settings);

        $this->put($url, ['settings' => null])->assertSessionHasNoErrors();
        $this->assertNull($voice->refresh()->settings);
    }

    public function test_dialogue_gives_each_line_its_voice_sample_and_favourite_settings(): void
    {
        $this->fakeEngine();
        $user = User::factory()->create();
        Storage::put('voices/c.wav', 'SAMPLE-CAMILLE');
        Storage::put('voices/p.wav', 'SAMPLE-PAUL');
        $camille = Voice::factory()->for($user)->create(['name' => 'Camille', 'sample_path' => 'voices/c.wav']);
        Voice::factory()->for($user)->create(['name' => 'Paul', 'sample_path' => 'voices/p.wav',
            'settings' => ['exaggeration' => 1.1, 'cfg_weight' => 0.2, 'temperature' => 0.6]]);

        $text = "Il était une fois.\n[camille] Bonjour Paul !\nTu vas bien ?\n[ Paul ]\nTrès bien.";
        $this->actingAs($user)->post('/generations', ['text' => $text, 'dialogue' => true] + $this->payload)
            ->assertSessionHasNoErrors();

        $generation = Generation::sole();
        $this->assertSame('completed', $generation->status);
        $this->assertSame('Dialogue : Voix par défaut, Camille, Paul', $generation->voice_name);
        $this->assertSame(
            [[null, 'Il était une fois.'], ['Camille', 'Bonjour Paul !'], ['Camille', 'Tu vas bien ?'], ['Paul', 'Très bien.']],
            $generation->segments->map(fn ($s) => [$s->voice_name, $s->text])->all(),
        );

        $sent = fn (string $text, callable $check) => Http::assertSent(
            fn (Request $r) => str_ends_with($r->url(), '/v1/speech') && str_contains($r->body(), $text) && $check($r->body()));
        $sent('Il était une fois.', fn ($b) => ! str_contains($b, 'SAMPLE-'));
        $sent('Tu vas bien ?', fn ($b) => str_contains($b, 'SAMPLE-CAMILLE') && preg_match('/name="exaggeration"\s+0\.5\s/', $b));
        $sent('Très bien.', fn ($b) => str_contains($b, 'SAMPLE-PAUL') && preg_match('/name="exaggeration"\s+1\.1\s/', $b));

        // Corriger une réplique ne touche pas au texte balisé
        $segment = $generation->segments[1];
        $this->postJson("/generations/{$generation->id}/segments/{$segment->id}/regenerate", ['text' => 'Salut Paul !'])
            ->assertOk()->assertJsonPath('segments.1.voice_name', 'Camille');
        $this->assertSame($text, $generation->refresh()->text);

        // Une voix supprimée ne doit pas être remplacée en silence par la voix par défaut
        $camille->delete();
        $this->postJson("/generations/{$generation->id}/segments/{$segment->id}/regenerate", ['text' => 'Coucou !']);
        $generation->refresh();
        $this->assertSame('failed', $generation->status);
        $this->assertSame('La voix « Camille » a été supprimée.', $generation->error);
    }

    public function test_dialogue_with_an_unknown_voice_is_refused_and_brackets_are_plain_text_otherwise(): void
    {
        $this->fakeEngine();
        $user = User::factory()->create();
        $text = '[Inconnue] Bonjour.';

        $this->actingAs($user)->post('/generations', ['text' => $text, 'dialogue' => true] + $this->payload)
            ->assertSessionHasErrors(['text' => 'Voix inconnue dans le dialogue : « Inconnue ».']);
        $this->assertSame(0, Generation::count());

        $this->post('/generations', ['text' => $text] + $this->payload)->assertSessionHasNoErrors();
        $this->assertSame('[Inconnue] Bonjour.', Generation::sole()->segments->sole()->text);
    }

    public function test_output_options_pauses_volume_title_and_formats(): void
    {
        $this->fakeEngine();
        $user = User::factory()->create();
        $options = ['title' => ' Épisode 3 : l\'été ', 'pause_ms' => 0, 'paragraph_pause_ms' => 2000, 'normalize' => true];

        $this->actingAs($user)->post('/generations', ['text' => "Un.\nDeux.", 'pause_ms' => 9999] + $this->payload)
            ->assertSessionHasErrors('pause_ms');
        $this->post('/generations', ['text' => "Un.\nDeux."] + $options + $this->payload)->assertSessionHasNoErrors();

        $generation = Generation::withProgress()->sole();
        $this->assertSame(500 + 2000 + 500, $generation->duration_ms);
        $this->assertSame('Épisode 3 : l\'été', $generation->title);
        // Volume homogène : le WAV final est produit par le moteur, puis converti en MP3
        $encodes = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/v1/encode'));
        $this->assertCount(2, $encodes);
        $this->assertSame('MP3DATA', Storage::get($generation->wav_path));

        $url = "/generations/{$generation->id}/audio";
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
        $this->get($url.'?download=1')->assertDownload('episode-3-lete.mp3');
        $this->get($url.'?download=wav')->assertDownload('episode-3-lete.wav');
        $this->assertStringEndsWith('&download=wav', $generation->present()['wav_url']);

        $this->patch("/generations/{$generation->id}", ['title' => ''])->assertSessionHasNoErrors();
        $this->get($url.'?download=mp3')->assertDownload("voix-{$generation->id}.mp3");
        $this->patch("/generations/{$generation->id}", ['title' => str_repeat('a', 121)])->assertSessionHasErrors('title');
    }

    public function test_without_volume_levelling_the_assembled_wav_is_kept_as_is(): void
    {
        $this->fakeEngine();
        $user = User::factory()->create();

        $this->actingAs($user)->post('/generations', ['text' => 'Un.', 'normalize' => false] + $this->payload);

        $generation = Generation::sole();
        $this->assertCount(1, Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/v1/encode')));
        $this->assertStringStartsWith('RIFF', Storage::get($generation->wav_path));
        $this->assertSame('MP3DATA', Storage::get($generation->audio_path));
    }
}
