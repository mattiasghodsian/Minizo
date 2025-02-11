<script setup>
import { ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import FolderIcon from '@/Components/Icons/Folder.vue';

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
});

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

const deleteFile = () => {
    console.log('Delete file', form.currentFile);
    toggleToolMenu.value = !toggleToolMenu.value;

    // form.post(route('dashboard'), {
    //     onFinish: () => {
    //         isDownloading.value = false;
    //         form.reset('url');
    //     },
    // });
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
                    <thead>
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider">File</th>
                            <th class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider">Format</th>
                            <th class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider">Size</th>
                            <th class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider">Last modified</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-700">
                        <tr v-for="(file, index) in files" :key="index" class="hover:bg-gray-700" @click.right="showToolMenu($event, file.name)">
                            <td class="px-6 py-4 whitespace-nowrap text-sm">{{ file.name }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">{{ file.format }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">{{ file.size }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">{{ file.last_modified }}</td>
                        </tr>
                    </tbody>

                    <!-- file action menu -->
                    <div 
                        v-show="toggleToolMenu" 
                        class="fixed z-50 bg-gray-800 border border-gray-700 rounded-lg shadow-md text-gray-400"
                        :style="{
                            top: `${menuPosition.y}px`,
                            left: `${menuPosition.x}px`
                        }"
                    >
                        <ul>
                            <li class="hover:bg-gray-700 px-5 py-2.5 cursor-pointer">Edit meta</li>
                            <li class="hover:bg-gray-700 px-5 py-2.5 cursor-pointer">Download</li>
                            <li class="hover:bg-gray-700 px-5 py-2.5 cursor-pointer" @click="deleteFile">Delete</li>
                        </ul>
                    </div>
                </table>
            </div>
        </div>
        
    </AuthenticatedLayout>
</template>
