<script setup>
import { onMounted, ref,computed } from 'vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false
    },
    title: {
        type: String,
        required: false
    },
    message: {
        type: String,
        required: true
    },
    type: {
        type: String,
        default: 'success',
        validator: (value) => ['success', 'error', 'warning'].includes(value)
    },
    duration: {
        type: Number,
        default: 4500
    }
});

const isVisible = ref(props.show);

const toastClasses = computed(() => {
    const baseClasses = 'max-w-sm w-full shadow-lg rounded-lg pointer-events-auto overflow-hidden';
    switch (props.type) {
        case 'success':
            return `${baseClasses} bg-green-800`;
        case 'error':
            return `${baseClasses} bg-red-800`;
        case 'warning':
            return `${baseClasses} bg-yellow-700`;
        default:
            return `${baseClasses} bg-green-800`;
    }
});

onMounted(() => {
    if (props.duration) {
        setTimeout(() => {
            isVisible.value = false;
        }, props.duration);
    }
});
</script>

<template>
    <Transition
        enter-active-class="transform ease-out duration-300 transition"
        enter-from-class="translate-y-2 opacity-0 sm:translate-y-0 sm:translate-x-2"
        enter-to-class="translate-y-0 opacity-100 sm:translate-x-0"
        leave-active-class="transition ease-in duration-100"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
    >
        <div v-show="isVisible" class="fixed top-4 right-4 z-50 min-w-96">
            <div :class="toastClasses">
                <div class="p-4">
                    <div class="flex items-start">
                        <div class="ml-3 w-0 flex-1">
                            <p class="text-sm font-medium text-white" v-if="props.title">
                                {{ props.title }}
                            </p>
                            <div class="mt-1 text-sm text-gray-200">
                               {{ props.message }}
                            </div>
                        </div>
                        <div class="ml-4 flex-shrink-0 flex">
                            <button
                                @click="isVisible = false"
                                class="inline-flex text-gray-200 hover:text-white focus:outline-none"
                            >
                                <span class="sr-only">Close</span>
                                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Transition>
</template>