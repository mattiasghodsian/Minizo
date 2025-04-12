<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});
</script>

<template>
    <Head title="Profile" />

    <AuthenticatedLayout>
        <template #header>
            <h2
                class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200"
            >
                Profile
            </h2>
        </template>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-10 md:gap-6">
            <div class="bg-gray-800 px-4 py-3 items-center relative rounded-lg shadow-md">
                <h1 class="text-white text-xl absolute uppercase -top-6 bg-gray-800 px-2 rounded-md">Profile</h1>
                <UpdateProfileInformationForm
                    :must-verify-email="mustVerifyEmail"
                    :status="status"
                    class="max-w-xl"
                />
            </div>
    
            <div class="bg-gray-800 px-4 py-3 items-center relative rounded-lg shadow-md">
                <h1 class="text-white text-xl absolute uppercase -top-6 bg-gray-800 px-2 rounded-md">Password</h1>
                <UpdatePasswordForm class="max-w-xl" />
            </div>
        </div>

    </AuthenticatedLayout>
</template>
