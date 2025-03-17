<script setup>
defineProps({
    rows: {
        type: Array,
        default: [],
    },
});

</script>
<template>
    <div class="bg-gray-800 items-center rounded-lg shadow-md text-gray-400 relative">
        <h1 class="text-white text-xl absolute uppercase -top-6 left-4 bg-gray-800 px-2 rounded-md">Queues</h1>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-gray-700">
                <thead>
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider">UUID</th>
                        <th class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider">URL</th>
                        <th class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider">Directory</th>
                        <th class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider">Format</th>
                        <th class="px-6 py-3 text-left text-xs font-bold uppercase tracking-wider">Timestamp</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-700">
                    <tr v-for="(row, index) in rows" :key="index" class="hover:bg-gray-700">
                        <td class="px-6 py-4 whitespace-nowrap text-sm">{{ row.uuid }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            <a :href="row.data.command.url" target="_blank" class="text-minizo-green">Open</a>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">{{ row.data.command.directory }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">{{ row.data.command.format }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">{{ row.data.created_at }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>