<script setup>
import { watch, ref } from 'vue';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { useAPIForm } from '@/Utils/useAPIForm';

const props = defineProps({
    show: {
        type: Boolean,
        default: false
    },
    filename: {
        type: String,
        default: ''
    },
    directory: {
        type: String,
        default: ''
    }
});

const emit = defineEmits(['close', 'success']);
const foundReleases = ref({});
const currentTab = ref(1);

const form = useAPIForm({
    file: props.filename,
    directory: props.directory,
    search: {
        title: '',
        artist: ''
    },
    releaseID: null,
    metaData: {}
});

const searchReleases = () => {
    form.get(route('library.metadata.search'), {
        onSuccess: (response) => {
            if (response.releases) {
                foundReleases.value = response.releases;
            } else {
                foundReleases.value = [];
            }
        }
    });
};

const getMetadata = () => {
    form.post(route('library.metadata.get'), {
        onSuccess: (response) => {
            form.metaData = response || {};
        },
        onError: (error) => {
            console.error(error);
        }
    });
};

const updateMetadata = () => {
    form.post(route('library.metadata.update'), {
        onSuccess: (response) => {
            if (response) {
                form.reset();
                emit('close');
            }
        },
        onError: (error) => {
            console.error(error);
        }
    });
};

const parseFileName = (filename) => {
    const nameWithoutExt = filename.substring(0, filename.lastIndexOf('.'));
    const parts = nameWithoutExt.split('-').map(part => part.trim());
    return {
        artist: parts[0] || '',
        title: parts[1] || ''
    };
};

const resetForm = () => {
    form.reset();
    currentTab.value = 1;
    foundReleases.value = {};
    form.metaData = {};
    emit('close'); 
};

watch(() => props.filename, (newFilename) => {
    form.file = newFilename;
    const parsedFile = parseFileName(newFilename);
    form.search = {
        title: parsedFile.title,
        artist: parsedFile.artist
    };
});

watch(() => props.directory, (newDirectory) => {
    form.directory = newDirectory;
});

watch(() => form.releaseID, (newReleaseID) => {
    if (newReleaseID) {
        getMetadata();
    }
});
</script>

<template>
    <Modal :show="show" @close="resetForm()" max-width="3xl">
        <div class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                Edit Metadata
            </h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                Update metadata for <span class="font-semibold">{{ form.file }}</span>
            </p>

            <div class="mt-6 space-y-4" v-show="currentTab === 1">

                <div>
                    <InputLabel for="artist" value="Artist" />
                    <TextInput
                        id="artist"
                        v-model="form.search.artist"
                        type="text"
                        class="mt-1 block w-full"
                    />
                </div>

                <div>
                    <InputLabel for="title" value="Title" />
                    <TextInput
                        id="title"
                        v-model="form.search.title"
                        type="text"
                        class="mt-1 block w-full"
                    />
                </div>

                <div>
                    <button 
                        type="button"
                        class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500 focus:bg-indigo-500 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
                        @click="searchReleases">
                        Search
                    </button>
                </div>

                <div class="relative overflow-hidden rounded-lg shadow-md mt-4" v-show="foundReleases.length > 0">
                    <div class="overflow-x-auto max-h-96">
                        <table class="w-full text-sm text-left text-gray-400">
                            <thead class="text-xs uppercase bg-gray-700">
                                <tr>
                                    <th scope="col" class="px-6 py-3 whitespace-nowrap">Release</th>
                                    <th scope="col" class="px-6 py-3 whitespace-nowrap">Artist</th>
                                    <th scope="col" class="px-6 py-3 whitespace-nowrap">Title</th>
                                    <th scope="col" class="px-6 py-3 whitespace-nowrap">Year</th>
                                    <th scope="col" class="px-6 py-3 whitespace-nowrap">Status</th>
                                    <th scope="col" class="px-6 py-3 whitespace-nowrap">Score</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="release in foundReleases" 
                                    :key="release.id" 
                                    @click="form.releaseID = release.id; currentTab++"
                                    class="bg-transparent cursor-pointer hover:text-white hover:bg-gray-700"
                                >
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <a :href="`https://musicbrainz.org/release/${release.id}`" 
                                        target="_blank"
                                        class="text-indigo-500 hover:text-indigo-400"
                                        @click.stop>
                                            {{ release.release_name }}
                                        </a>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">{{ release.artist_name }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap">{{ release.title }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap">{{ release.year }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap">{{ release.status }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap">{{ release.score }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            <div class="mt-6 space-y-4" v-show="currentTab === 2">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div v-for="(value, key) in form.metaData" :key="key" class="p-4 bg-gray-800 rounded-lg">
                        <div class="text-xs text-gray-400 uppercase mb-1">{{ key }}</div>
                        <div class="text-sm text-white break-words">
                            <template v-if="typeof value === 'string' && value.startsWith('http')">
                                <a :href="value" 
                                   target="_blank" 
                                   class="text-indigo-400 hover:text-indigo-300">
                                    {{ value }}
                                </a>
                            </template>
                            <template v-else>
                                {{ value }}
                            </template>
                        </div>
                    </div>
                </div>
            
                <div class="mt-4" v-if="form.metaData?.cover_art">
                    <img :src="form.metaData.cover_art" 
                         :alt="form.metaData.title" 
                         class="max-w-xs rounded-lg shadow-lg"
                    />
                </div>
            </div>

            <div class="mt-6 flex justify-between items-center" v-if="currentTab > 1">
                <div class="text-sm text-gray-400">
                    <span class="font-bold">Release ID:</span> {{ form.releaseID }}
                </div>
                <div class="flex justify-end gap-3">
                    <button
                        type="button"
                        class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
                        @click="currentTab--"
                        :disabled="form.processing"
                    >
                        Previous
                    </button>

                    <button
                        v-show="currentTab <= 1"
                        type="button"
                        class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500 focus:bg-indigo-500 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
                        @click="currentTab++"
                        :disabled="form.processing"
                        :class="{ 'opacity-50 cursor-not-allowed': form.processing }"
                    >
                        Next
                    </button>

                    <button
                        v-show="currentTab > 1"
                        type="button"
                        class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500 focus:bg-indigo-500 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
                        @click="updateMetadata()"
                        :class="{ 'opacity-50 cursor-not-allowed': form.processing }"
                    >
                        Write Metadata
                    </button>
                </div>
            </div>

        </div>
    </Modal>
</template>