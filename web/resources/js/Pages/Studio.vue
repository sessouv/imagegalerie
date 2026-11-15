<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import GenerationItem from '../Components/GenerationItem.vue';

const props = defineProps({
    voices: Array,
    languages: Object,
    maxChars: Number,
    generations: Array,
    engine: Object,
});

const form = useForm({
    text: '',
    language: 'fr',
    voice_id: null,
    exaggeration: 0.5,
    cfg_weight: 0.5,
    temperature: 0.8,
    seed: null,
});

const showAdvanced = ref(false);

// La langue suit celle de la voix choisie
watch(() => form.voice_id, (id) => {
    const voice = props.voices.find((v) => v.id === id);
    if (voice) form.language = voice.language;
});

const submit = () => form.post('/generations', { preserveScroll: true, preserveState: true });

const reuse = (generation) => {
    form.text = generation.text;
    form.language = generation.language;
    form.voice_id = props.voices.some((v) => v.id === generation.voice_id) ? generation.voice_id : null;
    Object.assign(form, generation.settings);
    window.scrollTo({ top: 0, behavior: 'smooth' });
};

// Rafraîchit la liste tant qu'un audio est en cours de génération
const busy = computed(() => props.generations.some((g) => ['pending', 'processing'].includes(g.status)));
let timer = null;
watch(busy, (value) => {
    clearInterval(timer);
    if (value) {
        timer = setInterval(() => router.reload({ only: ['generations'] }), 3000);
    }
}, { immediate: true });
onUnmounted(() => clearInterval(timer));

onMounted(() => {
    const draft = sessionStorage.getItem('timbre:draft');
    if (draft) {
        sessionStorage.removeItem('timbre:draft');
        reuse(JSON.parse(draft));
    }
});
</script>

<template>
    <Head title="Synthèse vocale" />

    <h1 class="text-2xl font-semibold tracking-tight">Synthèse vocale</h1>
    <p class="mt-1 text-sm text-black/60">Saisissez un texte, choisissez une voix, écoutez le résultat.</p>

    <div v-if="engine.status !== 'ok'" class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-900">
        <template v-if="engine.status === 'loading'">Le moteur vocal charge son modèle : les générations démarreront dès qu'il sera prêt.</template>
        <template v-else>Le moteur vocal est injoignable. Les générations échoueront tant qu'il n'est pas démarré.</template>
    </div>
    <div v-else-if="engine.driver === 'fake'" class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-900">
        Moteur en mode test : l'audio produit est un simple signal sonore, pas une voix.
    </div>

    <form class="mt-6 grid gap-6 lg:grid-cols-[1fr_20rem]" @submit.prevent="submit">
        <div class="card flex flex-col p-4">
            <label for="text" class="sr-only">Texte à lire</label>
            <textarea
                id="text"
                v-model="form.text"
                :maxlength="maxChars"
                rows="12"
                class="min-h-64 flex-1 resize-y border-0 bg-transparent text-base leading-relaxed outline-none placeholder:text-black/35"
                placeholder="Écrivez ou collez ici le texte à transformer en voix…"
            ></textarea>
            <div class="mt-3 flex items-center justify-between border-t border-black/10 pt-3">
                <span class="text-xs text-black/50">{{ form.text.length }} / {{ maxChars }} caractères</span>
                <button class="btn-primary" :disabled="form.processing || !form.text.trim()">Générer la voix</button>
            </div>
            <p v-if="form.errors.text" class="error">{{ form.errors.text }}</p>
        </div>

        <div class="card h-fit space-y-5 p-5">
            <div>
                <label class="label" for="voice">Voix</label>
                <select id="voice" v-model="form.voice_id" class="field">
                    <option :value="null">Voix par défaut</option>
                    <option v-for="voice in voices" :key="voice.id" :value="voice.id">{{ voice.name }}</option>
                </select>
                <Link href="/voices" class="mt-1 inline-block text-xs text-accent underline">Cloner une nouvelle voix</Link>
            </div>

            <div>
                <label class="label" for="language">Langue du texte</label>
                <select id="language" v-model="form.language" class="field">
                    <option v-for="(label, code) in languages" :key="code" :value="code">{{ label }}</option>
                </select>
            </div>

            <div>
                <label class="label flex justify-between" for="exaggeration">
                    Expressivité <span class="font-normal text-black/50">{{ Number(form.exaggeration).toFixed(2) }}</span>
                </label>
                <input id="exaggeration" v-model.number="form.exaggeration" type="range" min="0.25" max="1.5" step="0.05" class="w-full accent-accent" />
                <p class="text-xs text-black/50">Bas : neutre et posé. Haut : plus d'émotion, débit plus rapide.</p>
            </div>

            <button type="button" class="text-xs text-black/60 underline" @click="showAdvanced = !showAdvanced">
                {{ showAdvanced ? 'Masquer' : 'Afficher' }} les réglages avancés
            </button>

            <div v-show="showAdvanced" class="space-y-5">
                <div>
                    <label class="label flex justify-between" for="cfg">
                        Fidélité au rythme de la voix <span class="font-normal text-black/50">{{ Number(form.cfg_weight).toFixed(2) }}</span>
                    </label>
                    <input id="cfg" v-model.number="form.cfg_weight" type="range" min="0" max="1" step="0.05" class="w-full accent-accent" />
                    <p class="text-xs text-black/50">Baissez vers 0,3 si la voix parle trop vite ; à 0 si elle garde l'accent d'une autre langue.</p>
                </div>
                <div>
                    <label class="label flex justify-between" for="temperature">
                        Variabilité <span class="font-normal text-black/50">{{ Number(form.temperature).toFixed(2) }}</span>
                    </label>
                    <input id="temperature" v-model.number="form.temperature" type="range" min="0.2" max="1.4" step="0.05" class="w-full accent-accent" />
                </div>
                <div>
                    <label class="label" for="seed">Graine (facultatif)</label>
                    <input id="seed" v-model.number="form.seed" type="number" min="0" class="field" placeholder="Aléatoire" />
                    <p class="text-xs text-black/50">Un même nombre redonne le même rendu pour un même texte.</p>
                </div>
            </div>
        </div>
    </form>

    <section class="mt-10">
        <div class="flex items-baseline justify-between">
            <h2 class="text-lg font-semibold">Derniers audios</h2>
            <Link href="/generations" class="text-sm text-accent underline">Tout l'historique</Link>
        </div>
        <p v-if="!generations.length" class="mt-3 text-sm text-black/50">Aucun audio pour le moment.</p>
        <div class="mt-3 space-y-3">
            <GenerationItem v-for="generation in generations" :key="generation.id" :generation="generation" @reuse="reuse" />
        </div>
    </section>
</template>
