<script setup lang="ts">
import { ref, watch } from 'vue';
import { flatten } from 'flat';
import { useApiStore } from '@/stores/api.ts';
import { useToast } from 'primevue/usetoast';

const toast = useToast();
const apiStore = useApiStore();
const artist = ref<string>();
const track = ref<string>();
const metaData = ref<Object>();
const commonImage = ref<string>();

const props = defineProps({
  show: {
    type: Boolean,
    default: false
  },
  track: {
    type: Object,
    default: {
      file: 'Minizo - minizo.flac',
      name: 'Minizo - minizo',
      type: 'flac',
    }
  },
});

const emit = defineEmits([
  'close',
]);

const closeDialog = () => {
  emit('close');
} 

const trackName = (): string => {
  let trackname = "Minizo - Minizo"

  if (props.track.hasOwnProperty('name')) {
    trackname = props.track.name;
  }

  const parts = trackname.split(" - ");
  artist.value = parts[0];
  track.value = parts[1];
  return trackname;
}

const converImage = (data: Object) => {
  const imgData = data[0] ?? {};
  return new Promise((resolve, reject) => {
    try {
      const uint8Array = new Uint8Array(imgData.data.data);
      const blob = new Blob([uint8Array], { type: imgData.format });
      const reader = new FileReader();
      reader.onload = () => {
        resolve(reader.result as string);
      };
      reader.readAsDataURL(blob);
    } catch (error) {
      reject(error);
    }
  });
}

watch(
  () => props.show,
   async (newValue, oldValue) => {
    if (newValue != false){
      await apiStore.getFileMeta(props.track.file).then(res => {
        metaData.value = res;
        converImage(res.common.picture).then(res => {
          commonImage.value = res;
        })
      }).catch(err => {
        toast.add({
          severity: 'error',
          summary: 'Error',
          detail: err.data.message,
          life: 3000
        });
      });
    }
  }
);
</script>

<template>
  <Dialog :visible="show" modal :header="trackName()" @update:visible="closeDialog" :closable="true" class="bg-white"
    :style="{ 'max-width': '60rem' }" pt:root:class="!border-0 overflow-hidden !bg-minizo-dark !text-white" pt:header:class="!py-2"  pt:content:class="!px-1 !pb-3" 
    pt:mask:class="backdrop-blur-sm">
    <div class="flex flex-col h-96 overflow-y-auto" v-if="metaData">
      <div class="grid grid-cols-2 gap-4">
        <div v-for="(data, index) in metaData.common" :key="index" class="flex flex-col p-2">
          <span class="uppercase text-gray-500 border-b border-gray-700">{{ index }}</span>
          <span class="text-white" v-if="index !== 'picture'">{{ data }}</span>
          <span class="text-white" v-else><img :src="commonImage" /></span>
        </div>
        <div v-for="(data, index) in metaData.format" :key="index" class="flex flex-col p-2">
          <span class="uppercase text-gray-500 border-b border-gray-700">{{ index }}</span>
          <span class="text-white">{{ data }}</span>
        </div>
        <div v-for="(data, index) in metaData.tags" :key="index" class="flex flex-col p-2">
          <span class="uppercase text-gray-500 border-b border-gray-700">{{ data.id }}</span>
          <span class="text-white" v-if="data.id !== 'APIC' && data.id !== 'UFID' && data.id !== 'METADATA_BLOCK_PICTURE'">{{ data.value }}</span>
          <span class="text-white" v-else>Blob hidden</span>
        </div>
      </div>
    </div>
  </Dialog>
</template>