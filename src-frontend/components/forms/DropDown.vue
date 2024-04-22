<script setup lang="ts">
import { ref } from 'vue';
import IconCaretDown from '@/components/icons/IconCaretDown.vue';
import IconCaretUp from '@/components/icons/IconCaretUp.vue';
import ToolTip from '@/components/ToolTip.vue';

const selectedValue = ref<string | number>();
const toggleDropDown = ref<boolean>(false);

defineProps({
  label: {
    type: String,
    default: null,
  },
  data: {
    type: Array,
    required: true
  },
  tooltip: {
    type: String,
    default: null
  },
});

const emit = defineEmits([
  'input'
]);

const handler = (newValue: string | number): void => {
  toggleDropDown.value = !toggleDropDown.value;
  selectedValue.value = newValue;
  emit('input', selectedValue.value);
}
</script>

<template>
  <div class="flex justify-center gap-2 items-center">
    <ToolTip v-if="tooltip" class="shadow" :description="tooltip"></ToolTip>
    <div class="relative">
      <label v-if="label" class="text-sm ml-4">{{ label }}</label>
      <div class="flex w-full">
        <div class="flex w-full text-minizo-base items-center rounded-full bg-white h-[40px] pl-4 pr-11">
          <label class="min-w-[30px]">{{ selectedValue || 'Unselected' }}</label>
        </div>
        <BaseButton class="-ml-9 px-5 bg-minizo-red " @click="toggleDropDown = !toggleDropDown">
          <IconCaretDown v-if="!toggleDropDown" class="w-[19px] h-[19px]" />
          <IconCaretUp v-else class="w-[19px] h-[19px]" />
        </BaseButton>
      </div>
      <div v-if="toggleDropDown"
        class="absolute w-full bg-white shadow-lg text-minizo-base rounded-md overflow-y-auto max-h-[110px] z-10">
        <ul class="cursor-pointer rounded-md">
          <li v-for="(item, index) in data" :key="index" @click="handler(item)"
            class="hover:bg-minizo-base hover:text-white px-2 py-1">
            {{ item }}
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>