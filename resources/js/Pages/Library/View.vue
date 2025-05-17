<script setup>
import { ref, watch, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, usePoll, usePage } from '@inertiajs/vue3';
import {useToast} from 'vue-toast-notification';
import Pagination from '@/Components/Pagination.vue';
import MoveFile from './Partials/moveFile.vue';
import SearchYoutube from './Partials/searchYoutube.vue';
import DeleteFile from './Partials/deleteFile.vue';
import EditFile from './Partials/editFile.vue';

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
const showMoveModal = ref(false);
const showWriteMetaModal = ref(false);
const searchYoutubeRef = ref(null);
const deleteFileRef = ref(null);

// Long press detection for mobile
const longPressTimeout = ref(null);
const longPressDuration = 500; // ms

const handleTouchStart = (event, file) => {
    longPressTimeout.value = setTimeout(() => {
        const touch = event.touches[0];
        menuPosition.value = {
            x: touch.clientX,
            y: touch.clientY
        };
        toggleToolMenu.value = true;
        form.currentFile = file;
    }, longPressDuration);
};

const handleTouchEnd = () => {
    if (longPressTimeout.value) {
        clearTimeout(longPressTimeout.value);
        longPressTimeout.value = null;
    }
};

const showToolMenu = (event, file) => {
    event.preventDefault();
    menuPosition.value = {
        x: event.clientX,
        y: event.clientY
    };
    toggleToolMenu.value = !toggleToolMenu.value;
    form.currentFile = file;
};

const deleteFile = () => {
    toggleToolMenu.value = !toggleToolMenu.value;
    deleteFileRef.value.executeDelete();
};

const showMoveFileModal = () => {
    toggleToolMenu.value = !toggleToolMenu.value;
    showMoveModal.value = true;
};

const searchYT = () => {
    toggleToolMenu.value = !toggleToolMenu.value;
    searchYoutubeRef.value.executeSearch();
};

const showWriteMeta = () => {
    toggleToolMenu.value = !toggleToolMenu.value;
    showWriteMetaModal.value = true;
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
const sortField = ref('name_clean');
const sortDirection = ref('asc');

const totalPages = computed(() => {
    return Math.ceil(props.files.length / perPage.value);
});

const handlePageChange = (page) => {
    currentPage.value = page;
};

const sortFiles = (field) => {
    if (sortField.value === field) {
        // If already sorting by this field, toggle direction
        sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        // If sorting by a new field, default to ascending
        sortField.value = field;
        sortDirection.value = 'asc';
    }
};

const sortedFiles = computed(() => {
    return [...props.files].sort((a, b) => {
        let aValue = a[sortField.value];
        let bValue = b[sortField.value];
        
        // Handle string comparison
        if (typeof aValue === 'string' && typeof bValue === 'string') {
            aValue = aValue.toLowerCase();
            bValue = bValue.toLowerCase();
        }
        
        if (sortDirection.value === 'asc') {
            return aValue > bValue ? 1 : -1;
        } else {
            return aValue < bValue ? 1 : -1;
        }
    });
});

const paginatedFiles = computed(() => {
    const start = (currentPage.value - 1) * perPage.value;
    const end = start + perPage.value;
    return sortedFiles.value.slice(start, end);
});
</script>

<template>
    <Head :title="currentDirectory" />

    <AuthenticatedLayout>

        <MoveFile 
            :show="showMoveModal"
            :filename="form.currentFile"
            :source-directory="props.currentDirectory"
            :directories="$page.props.library.directories"
            @close="showMoveModal = false"
            @success="showMoveModal = false"
        />

        <EditFile
            :show="showWriteMetaModal"
            :filename="form.currentFile"
            :directory="currentDirectory"
            @close="showWriteMetaModal = false"
            @success="showWriteMetaModal = false"
        />

        <SearchYoutube
            ref="searchYoutubeRef"
            :filename="form.currentFile"
        />

        <DeleteFile
            ref="deleteFileRef"
            :filename="form.currentFile"
            :directory="props.currentDirectory"
        />

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
                            <th @click="sortFiles('name_clean')" class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider cursor-pointer">
                                File
                                <span v-if="sortField === 'name_clean'" class="ml-1">
                                    {{ sortDirection === 'asc' ? '▲' : '▼' }}
                                </span>
                            </th>
                            <th @click="sortFiles('format')" class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider cursor-pointer">
                                Format
                                <span v-if="sortField === 'format'" class="ml-1">
                                    {{ sortDirection === 'asc' ? '▲' : '▼' }}
                                </span>
                            </th>
                            <th @click="sortFiles('size')" class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider cursor-pointer">
                                Size
                                <span v-if="sortField === 'size'" class="ml-1">
                                    {{ sortDirection === 'asc' ? '▲' : '▼' }}
                                </span>
                            </th>
                            <th @click="sortFiles('last_modified')" class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider cursor-pointer">
                                Last modified
                                <span v-if="sortField === 'last_modified'" class="ml-1">
                                    {{ sortDirection === 'asc' ? '▲' : '▼' }}
                                </span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-700">
                        <tr 
                            v-for="(file, index) in paginatedFiles" 
                            :key="index" 
                            class="hover:bg-gray-700 hover:text-white" 
                            @click.right="showToolMenu($event, file.name)" 
                            @contextmenu.prevent
                            @touchstart="handleTouchStart($event, file.name)" 
                            @touchend="handleTouchEnd"
                            @touchmove="handleTouchEnd"
                        >
                            <td class="px-6 py-4 whitespace-nowrap text-sm max-w-96 truncate">{{ file.name_clean }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">{{ file.format }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">{{ file.size }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">{{ file.last_modified }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
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
                    <li class="hover:bg-gray-700 px-5 py-2.5 cursor-pointer" @click="showWriteMeta">Edit</li>
                    <li class="hover:bg-gray-700 px-5 py-2.5 cursor-pointer" @click="showMoveFileModal">Move</li>
                    <li class="hover:bg-gray-700 px-5 py-2.5 cursor-pointer" @click="searchYT">Search</li>
                    <li class="hover:bg-gray-700 px-5 py-2.5 cursor-pointer" @click="deleteFile">Delete</li>
                </ul>
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