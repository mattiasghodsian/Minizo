<script setup>
import { ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { useAPIForm } from '@/Utils/useAPIForm';

import InputError from '@/Components/InputError.vue';
import {useToast} from 'vue-toast-notification';

import EyeIcon from '@/Components/Icons/Eye.vue';
import XmarkIcon from '@/Components/Icons/Xmark.vue';

const props = defineProps({
    message: {
        type: String,
        default: '',
    },
    messageType: {
        type: String,
        default: '',
    },
    artists: {
        type: Array,
        default: [],
    },
});

const foundArtists = ref({});

const formSearch = useAPIForm({
    artist: ''
});

const Addform = useForm({
    artist: '',
    lastfm_url: '',
});

const MarkAsSeenform = useForm({
    id: '',
});

const submit = () => {
    foundArtists.value = {};
    formSearch.get(route('feed.search'), {
        onSuccess: (response) => {
            if (response.search) {
                foundArtists.value = response.search;
            } else {
                foundArtists.value = [];
            }
        }
    });
};

const submitAdd = (index) => {
    Addform.artist = foundArtists.value[index].name;
    Addform.lastfm_url = foundArtists.value[index].url;
    Addform.post(route('feed.add'), {
        preserveScroll: true,
        onSuccess: () => {
            Addform.artist = '';
            Addform.lastfm_url = '';
            clear();
        },
    });
};

const markAsSeen = (trackId) => {
    MarkAsSeenform.id = parseInt(trackId);
    MarkAsSeenform.post(route('feed.seen'), {
        preserveScroll: true,
        onSuccess: () => {
            MarkAsSeenform.id = '';
            clear();
        },
    });
};

const removeArtist = (artistId) => {
    if (confirm('Are you sure you want to remove this artist?')) {
        window.location.href = route('feed.remove', { id: artistId });
    }
};

const $toast = useToast();

const clear = () => {
    foundArtists.value = {};
    formSearch.artist = '';
}

watch(
    () => props.message,
    (newMessage) => {
        if (newMessage) {
            const cleanMessage = newMessage.includes('_') ? newMessage.split('_')[0] : newMessage;
            if (props.messageType === 'success') {
                $toast.success(cleanMessage);
            } else if (props.messageType === 'error') {
                $toast.error(cleanMessage);
            } else {
                $toast.info(cleanMessage);
            }
        }
    }
);
</script>

<template>
    <Head title="Feed" />
    <AuthenticatedLayout>

        <form @submit.prevent="submit" class="bg-gray-800 px-4 py-3 items-center relative rounded-lg shadow-md">
            <h1 class="text-white text-xl absolute uppercase -top-6 bg-gray-800 px-2 rounded-md">Add Artist</h1>
            <div class="flex flex-col md:flex-row gap-3">
                <div class="flex flex-grow items-center">
                    <TextInput
                        id="text"
                        type="text"
                        class="block w-full"
                        v-model="formSearch.artist"
                        placeholder="Eminem"
                        required
                        autofocus
                        @keydown.enter.prevent="submit()"
                    />
    
                    <InputError class="mt-2" :message="formSearch.errors.artist" />
                </div>
                <div class="flex items-center justify-center md:justify-normal">
                    <PrimaryButton :disabled="formSearch.processing" type="submit" class="bg-minizo-dark">
                    {{ formSearch.processing ? 'Searching...' : 'Search' }}
                    </PrimaryButton>
                    <PrimaryButton v-if="Object.keys(foundArtists).length > 0" @click="clear()" class="ml-1 bg-minizo-dark">Clear</PrimaryButton>
                </div>
            </div>
            <div v-if="Object.keys(foundArtists).length > 0" class="mt-2 mx-1">
                <div class="grid grid-cols-4 w-full">
                    <div v-for="(artist, index) in foundArtists" :key="index" @click="submitAdd(index)" class="flex items-center gap-2 p-1 hover:bg-gray-700 cursor-pointer bg-gray-800 rounded-lg mb-2">
                        <img v-if="artist.image"
                            :src="artist.image" 
                            :alt="artist.name"
                            class="w-20 h-20 object-cover rounded"
                        />
                        <div>
                            <h3 class="text-white text-lg">{{ artist.name }}</h3>
                            <p class="text-gray-400 text-sm">{{ artist.listeners }} listeners</p>
                        </div>
                    </div>
                </div>
            </div>
        </form>
        
        <section class="flex flex-col gap-4 mb-6" v-if="artists.length > 0">
            <div v-for="(artist, index) in artists" :key="index" class="w-full flex flex-col gap-2 border-l-4 border-transparent hover:border-gray-700 pl-2">
                <div class="flex items-center justify-between">
                    <h1 class="text-white text-xl">{{ artist.artist_name }}</h1>
                    <XmarkIcon 
                        class="w-7 h-7 fill-gray-400 cursor-pointer"
                        alt="Remove artist" 
                        aria-label="Remove artist"
                        @click="removeArtist(artist.id)"
                    />  
                </div>
                <div class="grid grid-cols-3 gap-2 p-2">
                    <div v-for="(track, tIndex) in artist.tracks" :key="tIndex" class="flex items-center p-1 gap-1 bg-gray-800 rounded-lg relative">
                        <!-- <img v-if="track.image_url"
                            :src="track.image_url" 
                            :alt="track.track_name"
                            class="w-20 h-20 object-cover rounded"
                        /> -->
                        <EyeIcon 
                            class="w-7 h-7 fill-gray-400 cursor-pointer absolute -right-2 -top-2"
                            alt="Mark as seen" 
                            aria-label="Mark as seen"
                            @click="markAsSeen(track.id)"
                        />    
                        <div class="p-2 text-white">
                            {{ track.track_name }} <br>
                            <a :href="track.lastfm_url" target="_blank" class="text-minizo-green">Last.fm</a>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        <section class="mb-6" v-if="artists.length === 0">
            <h1>Please add 1 or more artists to create the feed.</h1>
        </section>
    </AuthenticatedLayout>
</template>
