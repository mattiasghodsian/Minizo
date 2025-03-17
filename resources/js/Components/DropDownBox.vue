<script setup>
import { ref, defineExpose, onMounted, onUnmounted } from 'vue';

const props = defineProps({
    value: {
        type: String,
        default: '',
    },
    defaultValue: {
        type: String,
        required: true,
    }
});

const isOpen = ref(false);
const dropdownRef = ref(null);

const handleClickOutside = (event) => {
    if (dropdownRef.value && !dropdownRef.value.contains(event.target)) {
        close();
    }
};

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
});

const close = () => {
    isOpen.value = false;
};

defineExpose({ close });
</script>
<template>
    <div ref="dropdownRef" class="relative inline-block text-left">
        <button 
            @click="isOpen = !isOpen" 
            type="button"
            class="inline-flex w-full items-center justify-between rounded-md border border-gray-700 bg-minizo-dark px-4 py-2 font-medium text-gray-400 hover:bg-gray-700 focus:outline-none"
        >
            {{ props.value || props.defaultValue }}
            <svg 
                class="-mr-1 ml-2 h-4 w-4" 
                xmlns="http://www.w3.org/2000/svg" 
                viewBox="0 0 20 20" 
                fill="currentColor"
            >
                <path 
                    fill-rule="evenodd" 
                    d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" 
                    clip-rule="evenodd" 
                />
            </svg>
        </button>

        <div 
            v-show="isOpen"
            class="absolute overflow-hidden right-0 z-10 mt-2 w-56 origin-top-right rounded-md bg-minizo-dark shadow-lg ring-1 ring-black ring-opacity-5"
        >
            <div  
                role="menu" 
                aria-orientation="vertical"
            >
                <slot />
            </div>
        </div>
    </div>
</template>