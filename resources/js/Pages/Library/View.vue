<script setup>
import { ref, watch, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, usePoll, usePage } from '@inertiajs/vue3';
import {useToast} from 'vue-toast-notification';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({
    files: {
        type: Array,
    },
    totalFiles: {
        type: Number,
    },
    currentDirectory: {
        type: String,
    },
    message: {
        type: String,
        default: '',
    },
    messageType: {
        type: String,
        default: '',
    },
});

usePoll(10000, {
    only: ['files', 'totalFiles'],
}, {
    keepAlive: true,
    autoStart: true,
})

const form = useForm({
    currentFile: null,
    directory: props.currentDirectory,
});

const toggleToolMenu = ref(false);
const menuPosition = ref({ x: 0, y: 0 });

const showToolMenu = (event, file) => {
    event.preventDefault();
    menuPosition.value = {
        x: event.clientX,
        y: event.clientY
    };
    toggleToolMenu.value = !toggleToolMenu.value;
    form.currentFile = file;
};

/**
 * Delete the current file from the directory
 */
const deleteFile = () => {
    toggleToolMenu.value = !toggleToolMenu.value;
    form.delete(route('library.destroy', { directory: props.currentDirectory }), {
        preserveScroll: true,
        onFinish: () => {
            form.reset();
        },
    });
};

/**
 * Move file to another directory
 */
const MoveFile = () => {
    toggleToolMenu.value = !toggleToolMenu.value;
    // form.post(route('library.download', { directory: props.currentDirectory }), {
    //     preserveScroll: true,
    //     onFinish: () => {
    //         form.reset();
    //     },
    // });
};

/**
 * Search YouTube for the current file
 */
const SearchYT = () => {
    toggleToolMenu.value = !toggleToolMenu.value;

    const trackName = form.currentFile.replace(/\.[^/.]+$/, "");
    const query = trackName.replace(/\s+/g, '+');
    const url = `https://music.youtube.com/search?q=${query}`;
    window.open(url, '_blank');
};

/**
 * Write meta data for the current file
 */
const writeMeta = () => {
    toggleToolMenu.value = !toggleToolMenu.value;
    // form.post(route('library.meta', { directory: props.currentDirectory }), {
    //     preserveScroll: true,
    //     onFinish: () => {
    //         form.reset();
    //     },
    // });
};

const $toast = useToast();

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

const currentPage = ref(1);
const perPage = ref(usePage().props.auth.user.pagination_size ?? 10);

const paginatedFiles = computed(() => {
    const start = (currentPage.value - 1) * perPage.value;
    const end = start + perPage.value;
    return props.files.slice(start, end);
});

const totalPages = computed(() => {
    return Math.ceil(props.files.length / perPage.value);
});

const handlePageChange = (page) => {
    currentPage.value = page;
};
</script>

<template>
    <Head :title="currentDirectory" />

    <AuthenticatedLayout>
        <div class="flex items-center gap-3 text-gray-400">
            <FolderIcon class="w-7 h-7 fill-gray-400" />    
            <span class="text-lg">{{ currentDirectory }}</span> 
            <span class="bg-gray-800 px-2.5 py-0.5 rounded-full text-gray400">{{ totalFiles }}</span>   
        </div>
        
        <div v-if="totalFiles > 0" class="bg-gray-800 items-center rounded-lg shadow-md text-gray-400 relative">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-gray-700 relative">
                    <thead class="border-b border-gray-700">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider">File</th>
                            <th class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider">Format</th>
                            <th class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider">Size</th>
                            <th class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider">Last modified</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-700">
                        <tr v-for="(file, index) in paginatedFiles" :key="index" class="hover:bg-gray-700" @click.right="showToolMenu($event, file.name)">
                            <td class="px-6 py-4 whitespace-nowrap text-sm max-w-96 truncate">{{ file.name_clean }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">{{ file.format }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">{{ file.size }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">{{ file.last_modified }}</td>
                        </tr>
                    </tbody>

                    <!-- file action menu -->
                    <div 
                        v-show="toggleToolMenu" 
                        class="fixed z-50 bg-gray-800 border border-gray-700 min-w-32 rounded-lg shadow-md text-gray-400"
                        :style="{
                            top: `${menuPosition.y}px`,
                            left: `${menuPosition.x}px`
                        }"
                    >
                        <ul>
                            <li class="hover:bg-gray-700 px-5 py-2.5 cursor-pointer" @click="writeMeta">Edit</li>
                            <li class="hover:bg-gray-700 px-5 py-2.5 cursor-pointer" @click="MoveFile">Move</li>
                            <li class="hover:bg-gray-700 px-5 py-2.5 cursor-pointer" @click="SearchYT">Search</li>
                            <li class="hover:bg-gray-700 px-5 py-2.5 cursor-pointer" @click="deleteFile">Delete</li>
                        </ul>
                    </div>
                </table>
            </div>

            <div class="border-t border-gray-700 px-6 py-3">
                <Pagination
                    :current-page="currentPage"
                    :total-pages="totalPages"
                    :per-page="perPage"
                    :total-items="files.length"
                    @page-changed="handlePageChange"
                />
            </div>
        </div>
        
    </AuthenticatedLayout>
</template>
