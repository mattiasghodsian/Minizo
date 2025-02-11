<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    href: {
        type: String,
        required: true,
    },
    active: {
        type: Boolean,
    },
    external: {
        type: Boolean,
        default: false,
    },
});

const classes = computed(() =>
    props.active
        ? 'flex items-center gap-4 text-xl text-white fill-white bg-gray-800 px-6 py-2 border-l-2 border-minizo-green rounded-tr-lg rounded-br-lg'
        : 'flex items-center gap-4 text-xl text-gray-400 px-6 py-2 hover:bg-gray-800 border-l-2 border-transparent hover:text-white hover:border-l-2 hover:border-minizo-green hover:rounded-tr-lg hover:rounded-br-lg',
);
</script>

<template>
    <Link :href="href" :class="classes" v-if="!external">
        <slot />
    </Link>
    <a :href="href" class="classes" v-if="external" target="_blank">
        <slot />
    </a>
</template>
