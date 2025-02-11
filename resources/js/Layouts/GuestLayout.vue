<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link } from '@inertiajs/vue3';
import GithubIcon from '@/Components/Icons/Github.vue';
import DockerIcon from '@/Components/Icons/Docker.vue';
</script>

<template>
    <div
        class="flex min-h-screen flex-col items-center pt-6 sm:justify-center sm:pt-0 bg-minizo-dark"
    >
        <div>
            <Link href="/">
                <ApplicationLogo class="h-28" />
            </Link>
        </div>

        <div
            class="mt-6 w-full overflow-hidde px-6 py-4 sm:max-w-md sm:rounded-lg"
        >
            <slot />
        </div>

        <div class="mt-4">
            <ul class="flex gap-4">
                <li>
                    <a href="https://github.com/mattiasghodsian/Minizo" target="_blank">
                        <GithubIcon class="w-7 h-7 fill-gray-400 hover:fill-white transition-all delay-150" />
                    </a>
                </li>
                <li>
                    <a href="https://hub.docker.com/r/rakma/minizo" target="_blank">
                        <DockerIcon class="w-7 h-7 fill-gray-400 hover:fill-white transition-all delay-150" />
                    </a>
                </li>
            </ul>
        </div>
    </div>
</template>
