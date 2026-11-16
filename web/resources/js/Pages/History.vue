<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import GenerationItem from '../Components/GenerationItem.vue';
import { useGenerationPolling } from '../polling';

const props = defineProps({ generations: Object, projects: Array, unfiled: Number, filters: Object });

useGenerationPolling(() => props.generations.data);

const search = ref(props.filters.q ?? '');
const current = computed(() => props.projects.find((p) => String(p.id) === String(props.filters.project)));
const total = computed(() => props.projects.reduce((sum, p) => sum + p.generations_count, 0) + props.unfiled);

const visit = (project, q = search.value) => router.get(
    '/generations',
    { ...(q ? { q } : {}), ...(project ? { project } : {}) },
    { preserveState: true, preserveScroll: true, replace: true },
);

const clearSearch = () => { search.value = ''; visit(props.filters.project, ''); };

const reuse = (generation) => {
    sessionStorage.setItem('timbre:draft', JSON.stringify(generation));
    router.visit('/');
};

const createProject = () => {
    const name = prompt('Nom du nouveau projet :');
    if (name?.trim()) router.post('/projects', { name });
};
const renameProject = () => {
    const name = prompt('Nouveau nom du projet :', current.value.name);
    if (name?.trim()) router.put(`/projects/${current.value.id}`, { name }, { preserveScroll: true });
};
const deleteProject = () => {
    if (confirm(`Supprimer le projet « ${current.value.name} » ? Ses audios sont conservés, sans projet.`)) {
        router.delete(`/projects/${current.value.id}`);
    }
};

const chip = (active) => [
    'rounded-full border px-3 py-1 text-sm transition',
    active ? 'border-accent bg-accent text-white' : 'border-black/15 bg-white hover:bg-black/5',
];
</script>

<template>
    <Head title="Historique" />

    <h1 class="text-2xl font-semibold tracking-tight">Historique</h1>
    <p class="mt-1 text-sm text-black/60">Tous les audios générés par l'équipe, à ranger par projet.</p>

    <form class="mt-5 flex max-w-xl gap-2" role="search" @submit.prevent="visit(filters.project)">
        <label for="q" class="sr-only">Rechercher</label>
        <input id="q" v-model="search" type="search" class="field" placeholder="Rechercher dans les noms, les textes et les voix…" />
        <button class="btn-ghost">Rechercher</button>
    </form>

    <div class="mt-4 flex flex-wrap items-center gap-2">
        <button :class="chip(!filters.project)" @click="visit(null)">Tous ({{ total }})</button>
        <button v-for="project in projects" :key="project.id" :class="chip(current?.id === project.id)" @click="visit(project.id)">
            {{ project.name }} ({{ project.generations_count }})
        </button>
        <button v-if="projects.length" :class="chip(filters.project === 'none')" @click="visit('none')">Sans projet ({{ unfiled }})</button>
        <button class="rounded-full border border-dashed border-black/30 px-3 py-1 text-sm text-black/70 hover:bg-black/5" @click="createProject">
            + Nouveau projet
        </button>
    </div>

    <div v-if="current" class="mt-3 flex gap-4 text-xs">
        <button class="text-black/60 underline hover:text-black" @click="renameProject">Renommer ce projet</button>
        <button class="text-red-600/80 underline hover:text-red-700" @click="deleteProject">Supprimer ce projet</button>
    </div>

    <p class="mt-5 text-sm text-black/60">
        {{ generations.total }} audio(s)<template v-if="filters.q"> pour « {{ filters.q }} »
            <button class="ml-1 text-accent underline" @click="clearSearch">Effacer la recherche</button></template>
    </p>

    <div class="mt-3 space-y-3">
        <GenerationItem v-for="generation in generations.data" :key="generation.id" :generation="generation" :projects="projects" @reuse="reuse" />
    </div>

    <nav v-if="generations.prev_page_url || generations.next_page_url" class="mt-6 flex items-center gap-3 text-sm">
        <Link v-if="generations.prev_page_url" :href="generations.prev_page_url" class="btn-ghost">Précédent</Link>
        <span class="text-black/50">Page {{ generations.current_page }} / {{ generations.last_page }}</span>
        <Link v-if="generations.next_page_url" :href="generations.next_page_url" class="btn-ghost">Suivant</Link>
    </nav>
</template>
