<script setup>
defineProps({
    downloadHistory: {
        type: Array,
        default: [],
    },
});
</script>
<template>
    <div class="bg-gray-800 items-center rounded-lg shadow-md text-gray-400 relative">
        <h1 class="text-white text-xl absolute uppercase -top-6 bg-gray-800 px-2 rounded-md">History</h1>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-gray-700">
                <thead>
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider">File</th>
                        <th class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider">URL</th>
                        <th class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider">Directory</th>
                        <th class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider">Format</th>
                        <th class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider">Timestamp</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-700">
                    <tr v-for="(history, index) in downloadHistory" :key="index" class="hover:bg-gray-700">
                        <td class="px-6 py-4 whitespace-nowrap text-sm">{{ history.file }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            <a :href="history.url" target="_blank" class="text-minizo-green">Open</a>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">{{ history.directory }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">{{ history.format }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">{{ history.timestamp }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>