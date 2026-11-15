<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({ entries: Array, languages: Object });

const form = useForm({ term: '', replacement: '', language: null, case_sensitive: false });
const editing = ref(null);

function edit(entry) {
    editing.value = entry.id;
    form.clearErrors();
    form.term = entry.term;
    form.replacement = entry.replacement;
    form.language = entry.language;
    form.case_sensitive = entry.case_sensitive;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function cancel() {
    editing.value = null;
    form.reset();
    form.clearErrors();
}

function submit() {
    const options = { preserveScroll: true, onSuccess: cancel };
    if (editing.value) {
        form.put(`/pronunciations/${editing.value}`, options);
    } else {
        form.post('/pronunciations', options);
    }
}

function remove(entry) {
    if (confirm(`Retirer « ${entry.term} » du dictionnaire ?`)) {
        router.delete(`/pronunciations/${entry.id}`, { preserveScroll: true });
        if (editing.value === entry.id) cancel();
    }
}
</script>

<template>
    <Head title="Prononciation" />

    <h1 class="text-2xl font-semibold tracking-tight">Prononciation</h1>
    <p class="mt-1 max-w-3xl text-sm text-black/60">
        Indiquez une fois comment lire un nom propre, un sigle ou un mot étranger. Le remplacement est appliqué à chaque
        génération, au moment de la lecture : votre texte reste affiché tel que vous l'avez écrit.
    </p>

    <div class="mt-6 grid gap-6 lg:grid-cols-[24rem_1fr]">
        <form class="card h-fit space-y-4 p-5" @submit.prevent="submit">
            <h2 class="text-lg font-semibold">{{ editing ? 'Modifier l\'entrée' : 'Ajouter un mot' }}</h2>

            <div>
                <label class="label" for="term">Mot ou expression</label>
                <input id="term" v-model="form.term" class="field" maxlength="100" required placeholder="Ex. Qiryna" />
                <p v-if="form.errors.term" class="error">{{ form.errors.term }}</p>
            </div>

            <div>
                <label class="label" for="replacement">À lire comme</label>
                <input id="replacement" v-model="form.replacement" class="field" maxlength="200" required placeholder="Ex. Kirina" />
                <p class="mt-1 text-xs text-black/55">Écrivez le mot comme il se prononce, avec des mots ou syllabes ordinaires.</p>
                <p v-if="form.errors.replacement" class="error">{{ form.errors.replacement }}</p>
            </div>

            <div>
                <label class="label" for="planguage">Langue</label>
                <select id="planguage" v-model="form.language" class="field">
                    <option :value="null">Toutes les langues</option>
                    <option v-for="(label, code) in languages" :key="code" :value="code">{{ label }} uniquement</option>
                </select>
            </div>

            <label class="flex items-start gap-2 text-sm">
                <input v-model="form.case_sensitive" type="checkbox" class="mt-0.5 accent-accent" />
                <span>Respecter les majuscules et minuscules (utile pour un sigle : « SEO » mais pas « seo »)</span>
            </label>

            <div class="flex gap-2">
                <button class="btn-primary flex-1" :disabled="form.processing">{{ editing ? 'Enregistrer' : 'Ajouter' }}</button>
                <button v-if="editing" type="button" class="btn-ghost" @click="cancel">Annuler</button>
            </div>
        </form>

        <section>
            <h2 class="text-lg font-semibold">Dictionnaire ({{ entries.length }})</h2>
            <p v-if="!entries.length" class="mt-3 text-sm text-black/50">Aucun mot pour le moment.</p>
            <div v-else class="card mt-3 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-black/10 text-xs text-black/55">
                        <tr>
                            <th class="px-4 py-2 font-medium">Mot</th>
                            <th class="px-4 py-2 font-medium">Lu comme</th>
                            <th class="px-4 py-2 font-medium">Langue</th>
                            <th class="px-4 py-2"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-black/5">
                        <tr v-for="entry in entries" :key="entry.id" :class="editing === entry.id ? 'bg-accent/5' : ''">
                            <td class="px-4 py-2 font-medium">
                                {{ entry.term }}
                                <span v-if="entry.case_sensitive" class="ml-1 rounded bg-black/5 px-1.5 py-0.5 text-xs font-normal text-black/55">casse exacte</span>
                            </td>
                            <td class="px-4 py-2">{{ entry.replacement }}</td>
                            <td class="px-4 py-2 text-black/60">{{ entry.language ? languages[entry.language] : 'Toutes' }}</td>
                            <td class="px-4 py-2 text-right text-xs whitespace-nowrap">
                                <button class="text-black/60 underline hover:text-black" @click="edit(entry)">Modifier</button>
                                <button class="ml-3 text-red-600/80 underline hover:text-red-700" @click="remove(entry)">Retirer</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
