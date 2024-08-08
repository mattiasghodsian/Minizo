<script setup lang="ts">
import { RouterLink, useRoute } from 'vue-router';
import IconDownload from '@/components/icons/IconDownload.vue';
import IconFolder from "@/components/icons/IconFolder.vue"
import { useApiStore } from '@/stores/api.ts';

const apiStore = useApiStore();
const route = useRoute();

defineProps({
  toggle: {
    type: Boolean,
    default: false
  }
});

const isCurrentPage = (slug: string): boolean => {
  return (route.path == slug);
};
</script>

<template>
  <aside :class="{'hidden': toggle == true}" class="fixed flex h-full top-0 left-0 z-20 flex-col flex-shrink-0 w-64 pt-14 font-normal duration-75 lg:flex transition-width">
    <div class="relative flex flex-col flex-1 min-h-0 h-full pt-0 border-r border-gray-600 bg-gray-800">
      <div class="flex flex-col flex-1 pt-5 pb-4 overflow-y-auto">
        <div class="flex-1 px-3 space-y-1 divide-y divide-gray-600 bg-gray-800">
          <ul class="pb-2 space-y-2">
            <li>
              <RouterLink to="/" :class="{ 'font-bold bg-gray-700 text-gray-200': isCurrentPage('/') }" class="flex items-center p-2 text-base rounded-lg group text-gray-200 hover:bg-gray-700">
                  <IconDownload class="w-5 h-5 fill-gray-400 group-hover:fill-white" :class="{'fill-white': isCurrentPage('/') }" />
                  <span class="ml-3" sidebar-toggle-item="">Download</span>
              </RouterLink>
            </li>
          </ul>
          <div class="pt-2 space-y-2">
            <RouterLink v-for="(directory, index) in apiStore.directories" to="/manager" :key="index" class="flex items-center p-2 text-base transition duration-75 rounded-lg text-gray-200 hover:bg-gray-700">
              <IconFolder class="w-5 h-5 fill-gray-400 group-hover:fill-white"/>
              <span class="ml-3" sidebar-toggle-item="">{{ directory }}</span>
            </RouterLink>
          </div>
        </div>
      </div>
    </div>
  </aside>
</template>