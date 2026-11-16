<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { dateTime } from '../format';

const props = defineProps({ tokens: Array, newToken: String, baseUrl: String });

const form = useForm({ name: '' });
const copied = ref(false);

const submit = () => form.post('/api-keys', { preserveScroll: true, onSuccess: () => form.reset() });

async function copy() {
    try {
        await navigator.clipboard.writeText(props.newToken);
        copied.value = true;
    } catch {
        alert('Copie impossible : sélectionnez la clé à la main.');
    }
}

const revoke = (token) => {
    if (confirm(`Révoquer la clé « ${token.name} » ? Les sites qui l'utilisent n'auront plus accès.`)) {
        router.delete(`/api-keys/${token.id}`, { preserveScroll: true });
    }
};

const curl = `# 1. Demander un audio (réponse immédiate, la génération se fait en arrière-plan)
curl -X POST ${props.baseUrl}/generations \\
  -H "Authorization: Bearer VOTRE_CLE" -H "Accept: application/json" \\
  -d "text=Bonjour et bienvenue." -d "language=fr" -d "voice_id=1"

# 2. Suivre l'avancement jusqu'à "status": "completed"
curl ${props.baseUrl}/generations/42 -H "Authorization: Bearer VOTRE_CLE"

# 3. Télécharger le MP3 (ajouter ?format=wav pour le WAV)
curl -o audio.mp3 ${props.baseUrl}/generations/42/audio -H "Authorization: Bearer VOTRE_CLE"`;

const php = `use Illuminate\\Support\\Facades\\Http;

$timbre = Http::withToken(config('services.timbre.key'))
    ->acceptJson()
    ->baseUrl('${props.baseUrl}');

$id = $timbre->post('/generations', [
    'text' => 'Bonjour et bienvenue.',
    'language' => 'fr',
    'voice_id' => 1,
])->throw()->json('data.id');

// Plus tard (tâche planifiée ou job en file) :
$audio = $timbre->get("/generations/{$id}")->json('data');
if ($audio['status'] === 'completed') {
    Storage::put("audios/{$id}.mp3", $timbre->get("/generations/{$id}/audio")->body());
}`;

const endpoints = [
    ['GET', '/voices', 'Liste des voix (id, nom, langue).'],
    ['POST', '/generations', 'Crée un audio. Champs : text et language (obligatoires), voice_id, title, project_id, dialogue, exaggeration, cfg_weight, temperature, seed, pause_ms, paragraph_pause_ms, normalize.'],
    ['GET', '/generations/{id}', 'État : status (pending, processing, completed, failed), passages_done, passages_total, audio_url.'],
    ['GET', '/generations/{id}/audio', 'Fichier MP3, ou WAV avec ?format=wav.'],
    ['DELETE', '/generations/{id}', 'Supprime l\'audio.'],
    ['POST', '/transcriptions', 'Transcrit un fichier (champ file, multipart) ; language facultatif.'],
    ['GET', '/transcriptions/{id}', 'État, puis text et segments horodatés.'],
    ['DELETE', '/transcriptions/{id}', 'Supprime la transcription.'],
];
</script>

<template>
    <Head title="API" />

    <h1 class="text-2xl font-semibold tracking-tight">API</h1>
    <p class="mt-1 max-w-3xl text-sm text-black/60">
        Vos autres sites (Laravel, WordPress…) peuvent demander un audio ou une transcription à Timbre avec une clé d'accès.
        Le site appelant doit pouvoir joindre cette adresse : <code class="rounded bg-black/5 px-1">{{ baseUrl }}</code>
    </p>

    <div v-if="newToken" class="mt-5 max-w-3xl rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
        <p class="font-medium">Clé créée. Copiez-la maintenant : elle ne sera plus jamais affichée.</p>
        <div class="mt-2 flex flex-wrap items-center gap-2">
            <code class="rounded bg-white px-2 py-1 break-all">{{ newToken }}</code>
            <button class="btn-ghost" @click="copy">{{ copied ? 'Copiée' : 'Copier' }}</button>
        </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-[22rem_1fr]">
        <form class="card h-fit space-y-4 p-5" @submit.prevent="submit">
            <h2 class="text-lg font-semibold">Nouvelle clé</h2>
            <div>
                <label class="label" for="kname">À quoi servira-t-elle ?</label>
                <input id="kname" v-model="form.name" class="field" maxlength="80" required placeholder="Ex. Site Qiryna" />
                <p v-if="form.errors.name" class="error">{{ form.errors.name }}</p>
            </div>
            <button class="btn-primary w-full" :disabled="form.processing">Créer la clé</button>
        </form>

        <section>
            <h2 class="text-lg font-semibold">Clés actives ({{ tokens.length }})</h2>
            <p v-if="!tokens.length" class="mt-3 text-sm text-black/50">Aucune clé pour le moment.</p>
            <div v-else class="card mt-3 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-black/10 text-xs text-black/55">
                        <tr>
                            <th class="px-4 py-2 font-medium">Nom</th>
                            <th class="px-4 py-2 font-medium">Clé</th>
                            <th class="px-4 py-2 font-medium">Dernière utilisation</th>
                            <th class="px-4 py-2"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-black/5">
                        <tr v-for="token in tokens" :key="token.id">
                            <td class="px-4 py-2 font-medium">{{ token.name }}</td>
                            <td class="px-4 py-2 font-mono text-xs text-black/60">{{ token.hint }}…</td>
                            <td class="px-4 py-2 text-black/60">{{ token.last_used_at ? dateTime(token.last_used_at) : 'Jamais' }}</td>
                            <td class="px-4 py-2 text-right">
                                <button class="text-xs text-red-600/80 underline hover:text-red-700" @click="revoke(token)">Révoquer</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <section class="mt-10 max-w-4xl">
        <h2 class="text-lg font-semibold">Mode d'emploi</h2>
        <p class="mt-1 text-sm text-black/60">
            Chaque appel porte l'en-tête <code class="rounded bg-black/5 px-1">Authorization: Bearer VOTRE_CLE</code>.
            Les réponses sont en JSON, sous la clé <code class="rounded bg-black/5 px-1">data</code>.
        </p>

        <div class="card mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <tbody class="divide-y divide-black/5">
                    <tr v-for="[method, path, text] in endpoints" :key="method + path">
                        <td class="px-4 py-2 font-mono text-xs whitespace-nowrap">{{ method }} {{ path }}</td>
                        <td class="px-4 py-2 text-black/70">{{ text }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <h3 class="mt-6 font-medium">Exemple en ligne de commande</h3>
        <pre class="mt-2 overflow-x-auto rounded-lg bg-ink p-4 text-xs leading-relaxed text-white/90"><code>{{ curl }}</code></pre>

        <h3 class="mt-6 font-medium">Exemple en PHP (Laravel)</h3>
        <pre class="mt-2 overflow-x-auto rounded-lg bg-ink p-4 text-xs leading-relaxed text-white/90"><code>{{ php }}</code></pre>
    </section>
</template>
