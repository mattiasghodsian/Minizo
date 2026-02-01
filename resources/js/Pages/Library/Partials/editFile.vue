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
const foundReleases = ref([]);
const currentTab = ref(1);
const forceReleaseID = ref(0);
const availableTracks = ref([]);
const requiresTrackSelection = ref(false);
const searching = ref(false);
const hasSearched = ref(false);
const loadingTrackMetadata = ref(false);

const form = useAPIForm({
    file: props.filename,
    directory: props.directory,
    search: {
        title: '',
        artist: ''
    },
    releaseID: null,
    searchTitle: '',
    mediaPosition: null,
    trackIndex: null,
    metaData: {},
    rename: false,
});

const searchReleases = () => {
    searching.value = true;
    hasSearched.value = true;
    foundReleases.value = [];

    form.get(route('library.metadata.search'), {
        onSuccess: (response) => {
            searching.value = false;
            if (response.releases) {
                foundReleases.value = response.releases;
            } else {
                foundReleases.value = [];
            }
        },
        onError: (error) => {
            searching.value = false;
            console.error('Search error:', error);
            foundReleases.value = [];
        }
    });
};

const getMetadata = () => {
    form.searchTitle = form.search.title;
    loadingTrackMetadata.value = true;

    form.post(route('library.metadata.get'), {
        onSuccess: (response) => {
            loadingTrackMetadata.value = false;
            if (response.requires_track_selection) {
                // Multi-track release - show track selection
                availableTracks.value = response.tracks || [];
                requiresTrackSelection.value = true;
                currentTab.value = 2;
            } else {
                // Single track or track already selected
                form.metaData = response || {};
                requiresTrackSelection.value = false;
                currentTab.value = 3;
            }
        },
        onError: (error) => {
            loadingTrackMetadata.value = false;
            console.error(error);
        }
    });
};

const selectTrack = (track) => {
    form.mediaPosition = track.media_position;
    form.trackIndex = track.track_index;
    loadingTrackMetadata.value = true;

    // Fetch metadata for specific track
    form.post(route('library.metadata.get'), {
        onSuccess: (response) => {
            loadingTrackMetadata.value = false;
            form.metaData = response || {};
            currentTab.value = 3;
        },
        onError: (error) => {
            loadingTrackMetadata.value = false;
            console.error(error);
        }
    });
};

const updateMetadata = () => {
    form.post(route('library.metadata.update'), {
        onSuccess: (response) => {
            if (response) {
                form.reset();
                resetForm();
            }
        },
        onError: (error) => {
            console.error(error);
        }
    });
};

const parseFileName = (filename) => {
    if (!filename) {
        return { artist: '', title: '' };
    }

    const lastDotIndex = filename.lastIndexOf('.');
    const nameWithoutExt = lastDotIndex > 0 ? filename.substring(0, lastDotIndex) : filename;
    const parts = nameWithoutExt.split('-').map(part => part.trim());

    return {
        artist: parts[0] || '',
        title: parts[1] || ''
    };
};

const resetForm = () => {
    form.reset();
    currentTab.value = 1;
    foundReleases.value = [];
    form.metaData = {};
    forceReleaseID.value = 0;
    availableTracks.value = [];
    requiresTrackSelection.value = false;
    searching.value = false;
    hasSearched.value = false;
    loadingTrackMetadata.value = false;
    emit('close');
};

watch(() => props.filename, (newFilename) => {
    if (!newFilename) return;

    form.file = newFilename;
    const parsedFile = parseFileName(newFilename);
    // Update properties individually to maintain v-model reactivity
    form.search.title = parsedFile.title;
    form.search.artist = parsedFile.artist;
}, { immediate: true });

watch(() => props.directory, (newDirectory) => {
    if (!newDirectory) return;

    form.directory = newDirectory;
}, { immediate: true });

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

                <div v-if="forceReleaseID">
                    <InputLabel for="force_release_id" value="Force Release Id"/>
                    <div class="flex items-center justify-between gap-2">
                        <TextInput
                            id="force_release_id"
                            v-model="form.releaseID"
                            type="text"
                            class="block w-full mt-1"
                        />
                        <button 
                            type="button"
                            class="inline-flex items-center mt-1 px-4 py-2.5 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500 focus:bg-indigo-500 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
                            @click="currentTab++">
                            View
                        </button>
                    </div>
                </div>

                <div class="flex justify-between items-center mt-4">
                    <label class="flex items-center cursor-pointer">
                        <input
                            type="checkbox"
                            v-model="form.rename"
                            class="form-checkbox h-4 w-4 text-indigo-600 transition duration-150 ease-in-out"
                        />
                        <div class="flex items-center gap-2">
                            <span class="ml-2 text-sm text-gray-300">Rename file</span>
                            <span class="font-mono text-xs bg-slate-400 rounded px-1 py-0.5">%Artist% - %Track%.%extension%</span>
                        </div>
                    </label>

                    <label class="flex items-center cursor-pointer">
                        <input
                            type="checkbox"
                            v-model="forceReleaseID"
                            class="form-checkbox h-4 w-4 text-indigo-600 transition duration-150 ease-in-out"
                        />
                        <div class="flex items-center gap-2">
                            <span class="ml-2 text-sm text-gray-300">Force release id</span>
                        </div>
                    </label>

                    <button
                        type="button"
                        class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500 focus:bg-indigo-500 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
                        @click="searchReleases"
                        :disabled="searching">
                        <span v-if="searching">Searching...</span>
                        <span v-else>Search</span>
                    </button>
                </div>

                <!-- Loading indicator -->
                <div v-if="searching" class="mt-4 p-4 bg-blue-900 bg-opacity-20 border border-blue-600 rounded-lg">
                    <div class="flex items-center gap-3">
                        <svg class="animate-spin h-5 w-5 text-blue-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <p class="text-blue-400 text-sm">Searching for releases...</p>
                    </div>
                </div>

                <!-- No results message -->
                <div v-if="!searching && hasSearched && foundReleases.length === 0" class="mt-4 p-4 bg-yellow-900 bg-opacity-20 border border-yellow-600 rounded-lg">
                    <p class="text-yellow-400 text-sm">No releases found for "{{ form.search.artist }} - {{ form.search.title }}". Try adjusting your search terms.</p>
                </div>

                <div class="relative overflow-hidden rounded-lg shadow-md mt-4" v-show="foundReleases.length > 0">
                    <div class="overflow-x-auto max-h-96">
                        <table class="w-full text-sm text-left text-gray-400">
                            <thead class="text-xs uppercase bg-gray-700">
                                <tr>
                                    <th scope="col" class="px-6 py-3 whitespace-nowrap">Type</th>
                                    <th scope="col" class="px-6 py-3 whitespace-nowrap">Release</th>
                                    <th scope="col" class="px-6 py-3 whitespace-nowrap">Artist</th>
                                    <th scope="col" class="px-6 py-3 whitespace-nowrap">Title</th>
                                    <th scope="col" class="px-6 py-3 whitespace-nowrap">Tracks</th>
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
                                        <span class="px-2 py-1 text-xs rounded-full bg-gray-600 text-white">
                                            {{ release.primary_type || 'Release' }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center gap-2">
                                            <a :href="`https://musicbrainz.org/release/${release.id}`"
                                               target="_blank"
                                               class="text-indigo-500 hover:text-indigo-400"
                                               @click.stop>
                                                {{ release.release_name }}
                                            </a>
                                            <span v-if="release.track_count > 1"
                                                  class="text-yellow-400"
                                                  title="Track may be in this album - select from list">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                                    <path d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9z" />
                                                    <path fill-rule="evenodd" d="M4 5a2 2 0 012-2 3 3 0 003 3h2a3 3 0 003-3 2 2 0 012 2v11a2 2 0 01-2 2H6a2 2 0 01-2-2V5zm3 4a1 1 0 000 2h.01a1 1 0 100-2H7zm3 0a1 1 0 000 2h3a1 1 0 100-2h-3zm-3 4a1 1 0 100 2h.01a1 1 0 100-2H7zm3 0a1 1 0 100 2h3a1 1 0 100-2h-3z" clip-rule="evenodd" />
                                                </svg>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">{{ release.artist_name }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap">{{ release.title }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap">{{ release.track_count }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap">{{ release.year }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap">{{ release.status }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap">{{ release.score }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            <!-- Tab 2: Track Selection -->
            <div class="mt-6 space-y-4" v-show="currentTab === 2">
                <div class="bg-gray-800 p-4 rounded-lg">
                    <h3 class="text-white font-semibold mb-2">Select Track</h3>
                    <p class="text-gray-400 text-sm mb-4">
                        This release contains multiple tracks. Please select the correct track for "{{ form.search.title }}".
                    </p>
                </div>

                <!-- Loading indicator for track selection -->
                <div v-if="loadingTrackMetadata" class="p-4 bg-blue-900 bg-opacity-20 border border-blue-600 rounded-lg">
                    <div class="flex items-center gap-3">
                        <svg class="animate-spin h-5 w-5 text-blue-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <p class="text-blue-400 text-sm">Loading track metadata...</p>
                    </div>
                </div>

                <div class="relative overflow-hidden rounded-lg shadow-md" v-show="!loadingTrackMetadata">
                    <div class="overflow-x-auto max-h-96">
                        <table class="w-full text-sm text-left text-gray-400">
                            <thead class="text-xs uppercase bg-gray-700">
                                <tr>
                                    <th scope="col" class="px-6 py-3">#</th>
                                    <th scope="col" class="px-6 py-3">Title</th>
                                    <th scope="col" class="px-6 py-3">Duration</th>
                                    <th scope="col" class="px-6 py-3">Match</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="track in availableTracks"
                                    :key="`${track.media_position}-${track.track_index}`"
                                    @click="selectTrack(track)"
                                    class="cursor-pointer hover:text-white hover:bg-gray-700"
                                    :class="{'bg-green-900 bg-opacity-30': track.is_best_match}"
                                >
                                    <td class="px-6 py-4 whitespace-nowrap font-mono">
                                        {{ track.position }}
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-2">
                                            {{ track.title }}
                                            <span v-if="track.is_best_match"
                                                  class="px-2 py-0.5 text-xs bg-gray-600 text-white rounded-full">
                                                Best Match
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap font-mono">
                                        {{ track.length_formatted }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center gap-2">
                                            <div class="w-24 bg-gray-600 rounded-full h-2">
                                                <div class="bg-indigo-600 h-2 rounded-full"
                                                     :style="`width: ${track.match_score}%`">
                                                </div>
                                            </div>
                                            <span class="text-xs">{{ track.match_score }}%</span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div v-if="!loadingTrackMetadata" class="bg-yellow-900 bg-opacity-20 border border-yellow-600 p-3 rounded-lg">
                    <p class="text-yellow-400 text-sm">
                        Tip: The "Best Match" is automatically detected based on your search query
                    </p>
                </div>
            </div>

            <!-- Tab 3: Metadata Review -->
            <div class="mt-6 space-y-4" v-show="currentTab === 3">

                <div v-if="!form.metaData || Object.keys(form.metaData).length === 0" 
                    class="p-4 bg-gray-800 rounded-lg text-center">
                    <p class="text-gray-400">No metadata available for this release.</p>
                </div>

                <div v-else class="w-full">
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
            </div>

            <div class="mt-6 flex justify-between items-center" v-if="currentTab > 1">
                <div class="text-sm text-gray-400">
                    <span class="font-bold">Release ID:</span> {{ form.releaseID }}
                    <span v-if="form.trackIndex !== null" class="ml-3">
                        <span class="font-bold">Track:</span> {{ form.trackIndex + 1 }}
                    </span>
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
                        v-show="currentTab === 3"
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