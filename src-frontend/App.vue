<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { RouterView } from 'vue-router';
import NavBar from '@/components/NavBar.vue';
import { useApiStore } from '@/stores/api.ts';

import IconLogo from '@/components/icons/IconLogo.vue';
import IconGithub from '@/components/icons/IconGithub.vue';
import IconDocker from '@/components/icons/IconDocker.vue';
import IconBuyMeCoffe from '@/components/icons/IconBuyMeCoffe.vue';
import IconLibrepay from '@/components/icons/IconLibrepay.vue';

const toggleMenu = ref<boolean>(false);

const apiStore = useApiStore();

onMounted(async (): Promise<void> => {
  await apiStore.getAbout();
});
</script>

<template>
  <Toast />
  <nav class="fixed z-30 w-full bg-gray-800 border-b border-gray-600">
    <div class="px-3 py-3 lg:px-5 lg:pl-3">
      <div class="flex items-center justify-between">
        <div class="flex items-center justify-start">
          <button @click="toggleMenu = !toggleMenu"
            class="p-2 text-gray-600 rounded cursor-pointer lg:hidden hover:text-gray-900 hover:bg-gray-100 focus:bg-gray-700 focus:ring-2 focus:ring-gray-100 dark:focus:ring-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white">
            <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
              <path fill-rule="evenodd"
                d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h6a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z"
                clip-rule="evenodd"></path>
            </svg>
          </button>
          <label class="flex ml-2 md:mr-24">
            <IconLogo class="h-8 mr-3" />
            <span class="self-center text-xl font-semibold sm:text-2xl whitespace-nowrap dark:text-white">Minizo v{{
            apiStore.version }}
              <span class="text-xs" v-if="apiStore.environment == 'dev'"> [dev-mode]</span> </span>
          </label>
        </div>

        <div class="flex items-center">
          <a href="https://liberapay.com/mattiasghodsian" class="group sm:flex hover:bg-gray-500 hover:rounded p-2"
            target="_new">
            <IconLibrepay class="w-5 h-5 fill-gray-400 group-hover:fill-white" />
          </a>
          <a href="https://buymeacoffee.com/mattiasghodsian" class="group sm:flex hover:bg-gray-500 hover:rounded p-2"
            target="_new">
            <IconBuyMeCoffe class="w-5 h-5 fill-gray-400 group-hover:fill-white" />
          </a>
          <a href="https://hub.docker.com/r/rakma/minizo" class="group sm:flex hover:bg-gray-500 hover:rounded p-2"
            target="_new">
            <IconDocker class="w-5 h-5 fill-gray-400 group-hover:fill-white" />
          </a>
          <a href="https://github.com/mattiasghodsian/Minizo" class="group sm:flex hover:bg-gray-500 hover:rounded p-2"
            target="_new">
            <IconGithub class="w-5 h-5 fill-gray-400 group-hover:fill-white" />
          </a>
        </div>
      </div>
    </div>
  </nav>

  <div class="flex flex-col pt-16 overflow-hidden">
    <NavBar :toggle="toggleMenu" />
    <div class="w-[100%-16rem] relative h-full overflow-y-auto lg:ml-64 ">
      <main class="p-4 my-6 mx-4">
        <RouterView />
      </main>
    </div>
  </div>
</template>