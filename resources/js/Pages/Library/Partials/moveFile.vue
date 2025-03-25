<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false
    },
    filename: {
        type: String,
        default: ''
    },
    sourceDirectory: {
        type: String,
        default: ''
    },
    directories: {
        type: Array,
        default: () => []
    }
});

const emit = defineEmits(['close', 'success']);

const form = useForm({
    currentFile: props.filename,
    fromDirectory: props.sourceDirectory,
    toDirectory: '',
});

const executeMove = () => {
    form.post(route('library.move'), {
        preserveScroll: true,
        onSuccess: () => {
            emit('success');
            form.reset();
        },
        onFinish: () => {
            form.currentFile = props.filename;
            form.fromDirectory = props.sourceDirectory;
        }
    });
};

watch(() => props.filename, (newFilename) => {
    form.currentFile = newFilename;
});

watch(() => props.sourceDirectory, (newDirectory) => {
    form.fromDirectory = newDirectory;
});
</script>

<template>
    <Modal :show="show" @close="$emit('close')" max-width="md">
        <div class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                Move File
            </h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                Select a destination directory to move <span class="font-semibold">{{ form.currentFile }}</span>
            </p>

            <div class="mt-6">
                <div class="max-h-60 overflow-y-auto">
                    <div v-if="directories && directories.length > 0">
                        <div
                            v-for="directory in directories"
                            :key="directory.path"
                            class="p-2 hover:bg-gray-700 cursor-pointer rounded-md flex items-center"
                            :class="{ 'bg-gray-700': form.toDirectory === directory.path }"
                            @click="form.toDirectory = directory.path"
                        >
                            <FolderIcon class="w-5 h-5 mr-2 fill-gray-400" />
                            <span class="text-gray-300">{{ directory.name }}</span>
                        </div>
                    </div>
                    <div v-else class="text-gray-400 py-4 text-center">
                        No directories available
                    </div>
                </div>
            </div>
            <div class="mt-6 flex justify-end">
                <button
                    type="button"
                    class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150 mr-3"
                    @click="$emit('close')"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500 focus:bg-indigo-500 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
                    @click="executeMove"
                    :disabled="!form.toDirectory || form.processing"
                    :class="{ 'opacity-50 cursor-not-allowed': !form.toDirectory || form.processing }"
                >
                    Move
                </button>
            </div>
        </div>
    </Modal>
</template>