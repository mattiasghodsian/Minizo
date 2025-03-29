<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    filename: {
        type: String,
        default: ''
    },
    directory: {
        type: String,
        default: ''
    }
});

const emit = defineEmits(['success']);

const form = useForm({
    currentFile: props.filename,
    directory: props.directory,
});

watch(() => props.filename, (newFilename) => {
    form.currentFile = newFilename;
});

watch(() => props.directory, (newDirectory) => {
    form.directory = newDirectory;
});

const executeDelete = () => {
    if (!form.currentFile || !form.directory) {
        return;
    }
    
    form.delete(route('library.destroy', { directory: form.directory }), {
        preserveScroll: true,
        onFinish: () => {
            form.reset();
            form.currentFile = props.filename;
            form.directory = props.directory;
        },
    });
};

defineExpose({
    executeDelete
});
</script>

<template>
    <!-- Component has no visual -->
</template>