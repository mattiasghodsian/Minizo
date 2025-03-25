<script setup>
import { defineProps } from 'vue';

const props = defineProps({
    filename: {
        type: String,
        default: ''
    }
});

const executeSearch = () => {
    if (!props.filename) return;
    const trackName = props.filename.replace(/\.[^/.]+$/, "");
    const query = trackName.replace(/\s+/g, '+');
    const url = `https://music.youtube.com/search?q=${query}`;
    window.open(url, '_blank');
};

defineExpose({
    executeSearch
});
</script>

<template>
    <!-- Component has no visual -->
</template>