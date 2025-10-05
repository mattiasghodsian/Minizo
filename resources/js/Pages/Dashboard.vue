<script setup>
import { ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, usePoll,  } from '@inertiajs/vue3';

import InputError from '@/Components/InputError.vue';
import QueueTable from '@/Components/QueueTable.vue';
import {useToast} from 'vue-toast-notification';

const props = defineProps({
    message: {
        type: String,
        default: '',
    },
    messageType: {
        type: String,
        default: '',
    },
    queues: {
        type: Array,
        default: [],
    },
});

usePoll(5000, {
    only: ['queues'],
}, {
    keepAlive: true,
    autoStart: true,
})

const directoryRef = ref(null);
const formatRef = ref(null);

const form = useForm({
    url: '',
    directory: '',
    format: 'flac',
});

const SelectionDirectory = (directory) => {
    form.directory = directory;
    directoryRef.value?.close();
};

const SelectionFormat = (format) => {
    form.format = format;
    formatRef.value?.close();
};

const submit = () => {
    form.post(route('dashboard'), {
        preserveScroll: true,
        onSuccess: () => form.reset('url'),
    });
};

const $toast = useToast();

watch(
    () => props.message,
    (newMessage) => {
        if (newMessage) {
            const cleanMessage = newMessage.includes('_') ? newMessage.split('_')[0] : newMessage;
            if (props.messageType === 'success') {
                $toast.success(cleanMessage);
            } else if (props.messageType === 'error') {
                $toast.error(cleanMessage);
            } else {
                $toast.info(cleanMessage);
            }
        }
    }
);
</script>

<template>
    <Head title="Download" />

    <AuthenticatedLayout>

        <form @submit.prevent="submit" class="bg-gray-800 px-4 py-3 items-center relative rounded-lg shadow-md">
            <h1 class="text-white text-xl absolute uppercase -top-6 bg-gray-800 px-2 rounded-md">Download</h1>
            <div class="flex flex-col md:flex-row gap-3">
                <div class="flex flex-grow items-center">
                    <TextInput
                        id="url"
                        type="url"
                        class="block w-full"
                        v-model="form.url"
                        placeholder="https://music.youtube.com/watch?v=xxxxxxxxxxx"
                        required
                        autofocus
                    />
    
                    <InputError class="mt-2" :message="form.errors.url" />
                </div>
                <div class="flex items-center">
                    <DropDownBox :value="form.directory" class="w-full md:w-auto" defaultValue="Select Directory" ref="directoryRef">
                        <span
                            v-for="(directory, index) in $page.props.library.directories"
                            :key="index"
                            @click="SelectionDirectory(directory.name)"
                            class="block w-full px-4 py-2 text-left cursor-pointer text-gray-400 hover:bg-gray-700 hover:text-gray-200"
                            role="menuitem"
                        >
                            {{ directory.name }}
                        </span>
                    </DropDownBox>
                </div>
                <div class="flex items-center">
                    <DropDownBox :value="form.format" class="w-full md:w-auto" default-value="Select format" ref="formatRef">
                        <span
                            v-for="(format, index) in  $page.props.library.formats"
                            :key="index"
                            @click="SelectionFormat(format)"
                            class="block w-full px-4 py-2 text-left cursor-pointer text-gray-400 hover:bg-gray-700 hover:text-gray-200"
                            role="menuitem"
                        >
                            {{ format }}
                        </span>
                    </DropDownBox>
                </div>
                <div class="flex items-center justify-center md:justify-normal">
                    <PrimaryButton :disabled="form.processing" type="submit" class="bg-minizo-dark">Download</PrimaryButton>
                  
                </div>
            </div>
        </form>
        
        <div class="flex text-sm text-gray-400 text-center -mt-5 px-5">
            <p>Downloading copyrighted content without authorization is illegal. This project is for educational purposes only. Ensure you have the right to download and use the content.</p>
        </div>
    
        <QueueTable :rows="queues" />
    </AuthenticatedLayout>
</template>
