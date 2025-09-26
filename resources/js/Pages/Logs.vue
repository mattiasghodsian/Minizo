<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePoll } from '@inertiajs/vue3';
import { ref, watch, nextTick } from 'vue';

const logContainers = ref([]);
const autoScroll = ref({}); // Store auto-scroll state for each log

const scrollToBottom = (index) => {
    if (logContainers.value[index] && autoScroll.value[index]) {
        logContainers.value[index].scrollTop = logContainers.value[index].scrollHeight;
    }
};

const toggleAutoScroll = (index) => {
    autoScroll.value[index] = !autoScroll.value[index];
    if (autoScroll.value[index]) {
        scrollToBottom(index);
    }
};

const props = defineProps({
    logs: Array
});

// Initialize auto-scroll state for each log
watch(() => props.logs, (newLogs) => {
    newLogs.forEach((_, index) => {
        if (autoScroll.value[index] === undefined) {
            autoScroll.value[index] = true;
        }
    });
    nextTick(() => {
        logContainers.value.forEach((_, index) => scrollToBottom(index));
    });
}, { immediate: true, deep: true });

usePoll(1000, {
    only: ['logs'],
}, {
    keepAlive: true,
    autoStart: true,
})
</script>

<template>
    <Head title="Logs" />
    <AuthenticatedLayout>


            <div class="w-full mx-auto sm:px-6 lg:px-8">
                <div class="overflow-hidden">
                    <div class="p-6">
                        <div v-for="(log, index) in logs" :key="log.name" class="mb-8">
                            <div class="flex justify-between items-center mb-2">
                                <h2 class="text-xl font-bold text-gray-400">
                                    {{ log.name }} 
                                    <span class="text-sm bg-gray-400 text-minizo-dark px-1 rounded">Last updated: {{ log.updated }}</span>
                                </h2>
                                <div class="flex items-center gap-4">
                                    <button 
                                        @click="toggleAutoScroll(index)"
                                        class="px-3 py-1 text-sm rounded"
                                        :class="autoScroll[index] ? 'bg-minizo-green text-minizo-dark' : 'bg-gray-400 text-gray-700'"
                                    >
                                        {{ autoScroll[index] ? 'Auto-scroll ON' : 'Auto-scroll OFF' }}
                                    </button>
                                </div>
                            </div>
                            <div 
                                ref="logContainers"
                                class="bg-gray-900 text-gray-100 overflow-auto max-h-[500px]"
                            >
                                <pre v-for="(line, lineIndex) in log.content" 
                                     :key="lineIndex" 
                                     class="font-mono text-sm">{{ line }}</pre>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
      
    </AuthenticatedLayout>
</template>