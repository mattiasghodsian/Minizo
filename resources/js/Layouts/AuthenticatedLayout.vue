<script setup>
import { ref, watch } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import NavLink from '@/Components/NavLink.vue';
import { Link, router } from '@inertiajs/vue3';
import DownloadIcon from '@/Components/Icons/Download.vue';
import GithubIcon from '@/Components/Icons/Github.vue';
import DockerIcon from '@/Components/Icons/Docker.vue'; 
import CogIcon from '@/Components/Icons/Cog.vue';
import LogoutIcon from '@/Components/Icons/Logout.vue';

const showingNavigationDropdown = ref(false);

// Watch for changes to showingNavigationDropdown and toggle body scroll
watch(showingNavigationDropdown, (value) => {
    if (value) {
        document.body.style.overflow = 'hidden';
    } else {
        document.body.style.overflow = '';
    }
});

const logout = () => {
    router.post(route('logout'));
};
</script>

<template>
    <div>

        <div class="flex flex-col gap-10 min-h-screen bg-minizo-dark">
            <div>
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="flex h-16 justify-between">
                        <div class="flex">
                            <!-- Logo -->
                            <div class="flex shrink-0 items-center">
                                <Link :href="route('dashboard')" class="flex gap-2 items-center">
                                <ApplicationLogo class="block h-10 w-auto" />
                                <span class="text-white">Minizo</span>
                                </Link>
                            </div>
                        </div>


                        <div class="hidden sm:ms-6 sm:flex sm:items-center">
                            <ul class="flex gap-6">
                                <li>
                                    <a href="https://github.com/mattiasghodsian/Minizo" class="flex items-center gap-4 text-lg group text-gray-400 p hover:text-white" target="_blank">
                                        <GithubIcon class="w-7 h-7 fill-gray-400 group-hover:fill-white" />
                                        Github
                                    </a>
                                </li>
                                <li>
                                    <a href="https://hub.docker.com/r/rakma/minizo" class="flex items-center gap-4 text-lg group text-gray-400 p hover:text-white" target="_blank">
                                        <DockerIcon class="w-7 h-7 fill-gray-400 group-hover:fill-white" />
                                        Docker
                                    </a>
                                </li>
                            </ul>
                        </div>

                        <!-- Hamburger -->
                        <div class="-me-2 flex items-center md:hidden">
                            <button @click="
                                showingNavigationDropdown =
                                !showingNavigationDropdown
                                "
                                class="inline-flex items-center justify-center rounded-md p-2 text-gray-400 transition duration-150 ease-in-out hover:bg-gray-100 hover:text-gray-500 focus:bg-gray-100 focus:text-gray-500 focus:outline-none dark:text-gray-500 dark:hover:bg-gray-900 dark:hover:text-gray-400 dark:focus:bg-gray-900 dark:focus:text-gray-400">
                                <svg class="h-5 w-5" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                                    <path :class="{
                                        hidden: showingNavigationDropdown,
                                        'inline-flex':
                                            !showingNavigationDropdown,
                                    }" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M4 6h16M4 12h16M4 18h16" />
                                    <path :class="{
                                        hidden: !showingNavigationDropdown,
                                        'inline-flex':
                                            showingNavigationDropdown,
                                    }" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex flex-row gap-12 w-full mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <!-- Navigation Links -->
                <nav class="min-w-[200px] bg-minizo-dark z-10" 
                     :class="{ 
                        'fixed top-16 left-0 right-0 bottom-0 h-[calc(100%-4rem)] w-full max-w-full overflow-auto px-4 sm:px-6 lg:px-8 sm:relative sm:top-0 sm:h-auto sm:px-0': showingNavigationDropdown, 
                        'hidden sm:block': !showingNavigationDropdown 
                     }">
                    <ul class="flex flex-col gap-3">
                        <li>
                            <NavLink :href="route('dashboard')" :active="route().current('dashboard')"
                            >
                                <DownloadIcon class="h-6 w-6 fill-gray-400" />
                                Download
                            </NavLink>
                        </li>
                        <li class="border-b border-gray-800 my-2 mx-4"></li>
                    </ul>
                    <ul class="flex flex-col gap-3 max-h-52 md:max-h-[548px] overflow-y-auto">
                        <li v-for="directory in $page.props.library.directories" :key="directory.id">
                            <NavLink 
                                :href="route('library', { directory: directory.name })"
                                :active="route().current('library') && $page.props.currentDirectory === directory.name"
                            >
                                {{ directory.name }}
                            </NavLink>
                        </li>
                    </ul>
                    <ul class="flex flex-col gap-3">
                        <li class="border-b border-gray-800 my-2 mx-4"></li>
                        <li>
                            <NavLink :href="route('profile.edit')">
                                <CogIcon class="w-6 h-6 fill-gray-400" />
                                {{ $page.props.auth.user.name }}
                            </NavLink>
                        </li>
                        <li @click="logout">
                            <NavLink href="#">
                                <LogoutIcon class="w-6 h-6 fill-gray-400" />
                                Logout
                            </NavLink>
                        </li>
                    </ul>
                    <ul class="flex flex-col gap-3 md:hidden">
                        <li class="border-b border-gray-800 my-2 mx-4"></li>
                        <li class="px-6 py-2">
                            <a href="https://github.com/mattiasghodsian/Minizo" class="flex items-center gap-4 text-lg group text-gray-400 p hover:text-white" target="_blank">
                                <GithubIcon class="w-7 h-7 fill-gray-400 group-hover:fill-white" />
                                Github
                            </a>
                        </li>
                        <li class="px-6 py-2">
                            <a href="https://hub.docker.com/r/rakma/minizo" class="flex items-center gap-4 text-lg group text-gray-400 p hover:text-white" target="_blank">
                                <DockerIcon class="w-7 h-7 fill-gray-400 group-hover:fill-white" />
                                Docker
                            </a>
                        </li>
                    </ul>
                </nav>

                <!-- Page Content -->
                <main class="flex flex-col gap-10 w-full max-w-[968px] pb-4">
                    <slot />
                </main>
            </div>

        </div>
    </div>
</template>
