<script setup>
const props = defineProps({
    currentPage: {
        type: Number,
        required: true
    },
    totalPages: {
        type: Number,
        required: true
    },
    perPage: {
        type: Number,
        required: true
    },
    totalItems: {
        type: Number,
        required: true
    }
});

const emit = defineEmits(['page-changed']);

const changePage = (page) => {
    if (page >= 1 && page <= props.totalPages) {
        emit('page-changed', page);
    }
};
</script>

<template>
    <div class="flex items-center justify-between">
        <div class="flex flex-1 justify-between sm:hidden">
            <button 
                @click="changePage(currentPage - 1)"
                :disabled="currentPage === 1"
                class="relative inline-flex items-center px-4 py-2 text-sm font-medium rounded-md text-gray-400 bg-gray-800 hover:bg-gray-700 disabled:opacity-50"
            >
                Previous
            </button>
            <button 
                @click="changePage(currentPage + 1)"
                :disabled="currentPage === totalPages"
                class="relative ml-3 inline-flex items-center px-4 py-2 text-sm font-medium rounded-md text-gray-400 bg-gray-800 hover:bg-gray-700 disabled:opacity-50"
            >
                Next
            </button>
        </div>
        <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
            <div>
                <p class="text-sm text-gray-400">
                    Showing
                    <span class="font-medium">{{ ((currentPage - 1) * perPage) + 1 }}</span>
                    to
                    <span class="font-medium">{{ Math.min(currentPage * perPage, totalItems) }}</span>
                    of
                    <span class="font-medium">{{ totalItems }}</span>
                    results
                </p>
            </div>
            <div>
                <nav class="isolate inline-flex -space-x-px rounded-md shadow-sm gap-1">
                    <button
                        @click="changePage(currentPage - 1)"
                        :disabled="currentPage === 1"
                        class="relative inline-flex items-center px-3.5 py-1 rounded-full text-gray-400 bg-gray-800 hover:bg-gray-700 disabled:opacity-50"
                    >
                        <span class="sr-only">Previous</span>
                        &larr;
                    </button>
                    <button
                        v-for="page in totalPages"
                        :key="page"
                        @click="changePage(page)"
                        :class="[
                            page === currentPage ? 'bg-gray-700' : 'bg-gray-800 hover:bg-gray-700',
                            'relative inline-flex items-center px-3.5 py-1 text-sm rounded-full font-medium text-gray-400'
                        ]"
                    >
                        {{ page }}
                    </button>
                    <button
                        @click="changePage(currentPage + 1)"
                        :disabled="currentPage === totalPages"
                        class="relative inline-flex items-center px-3.5 py-1 rounded-full text-gray-400 bg-gray-800 hover:bg-gray-700 disabled:opacity-50"
                    >
                        <span class="sr-only">Next</span>
                        &rarr;
                    </button>
                </nav>
            </div>
        </div>
    </div>
</template>