<script setup>
import { ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import DropDownBox from '@/Components/DropDownBox.vue';
import DownloadHistory from '@/Components/DownloadHistory.vue';
import Toast from '@/Components/Toast.vue';
import LoadingIcon from '@/Components/Icons/Loading.vue';

defineProps({
    formats: {
        type: Array,
    },
    downloadHistory: {
        type: Array,
        default: [],
    },
    file: {
        type: Object,
        default: null,
    },
});

const directoryRef = ref(null);
const formatRef = ref(null);
const isDownloading = ref(false);

const form = useForm({
    url: '',
    directory: '',
    format: '',
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
    isDownloading.value = true;
    form.post(route('dashboard'), {
        onFinish: () => {
            isDownloading.value = false;
            form.reset('url');
        },
    });
};
</script>

<template>
    <Head title="Download" />

    <AuthenticatedLayout>

        <Toast 
            v-if="file" 
            :show="!file.error" 
            title="Download Complete!"
            :message="`${file.title} downloaded to ${file.directory}`" 
            type="success"
        />
        <Toast 
            v-if="file" 
            :show="file.error" 
            title="Download Complete!"
            :message="file.error" 
            type="success"
        />

        <form @submit.prevent="submit" class="bg-gray-800 px-4 py-3 items-center relative rounded-lg shadow-md">
            <h1 class="text-white text-xl absolute uppercase -top-6 bg-gray-800 px-2 rounded-md">Download</h1>
            <div class="flex gap-3">
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
                    <DropDownBox :value="form.directory" defaultValue="Select Directory" ref="directoryRef">
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
                    <DropDownBox :value="form.format" default-value="Select format" ref="formatRef">
                        <span
                            v-for="(format, index) in formats"
                            :key="index"
                            @click="SelectionFormat(format)"
                            class="block w-full px-4 py-2 text-left cursor-pointer text-gray-400 hover:bg-gray-700 hover:text-gray-200"
                            role="menuitem"
                        >
                            {{ format }}
                        </span>
                    </DropDownBox>
                </div>
                <div class="flex items-center">
                    <PrimaryButton v-if="!isDownloading" type="submit" class="bg-minizo-dark">Download</PrimaryButton>
                    <span v-else>
                        <LoadingIcon class="animate-spin w-h8 h-8 fill-gray-400" />
                    </span>
                </div>
            </div>
        </form>
        
        <div class="flex text-sm text-gray-400 text-center -mt-5 px-5">
            <p>Downloading copyrighted content without authorization is illegal. This project is for educational purposes only. Ensure you have the right to download and use the content.</p>
        </div>
    
        <DownloadHistory :downloadHistory="downloadHistory" v-if="downloadHistory.length > 0" />
    </AuthenticatedLayout>
</template>
