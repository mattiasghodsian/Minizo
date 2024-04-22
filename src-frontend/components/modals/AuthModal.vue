<script setup lang="ts">
import { ref } from 'vue';
import BaseModal from '@/components/modals/BaseModal.vue';
import { useApiStore } from '@/stores/api.ts';
import ErrorMessage from '@/components/ErrorMessage.vue';

const apiStore = useApiStore();
const username = ref<string>();
const password = ref<string>();
const error = ref<string>('');

const updateProperty = (propertyName: string, value: any): void => {
  switch (propertyName) {
    case 'username':
    username.value = value;
      break;
    case 'password':
      password.value = value;
      break;
  }

  error.value = null;
}

const handler = async (): Promise<void> => {
  if (!username.value || username.value.length == 0){
    error.value = "Please provide a username!";
  }

  if (!password.value || password.value.length == 0){
    error.value = "Please provide a password!";
  }

  // store usr/pass
  apiStore.username = username.value;
  apiStore.password = password.value;

  await apiStore.testAuth().then(response => {
    // do nothing
  }).catch( err => {
    error.value = err.data.message;
  });
}

</script>

<template>
  <BaseModal header="Authenticate" headerSmall="Basic Auth">
    <ErrorMessage v-if="error" :message="error" />
    <TextInput
    class="bg-minizo-base text-white"
    placeholder="username"
    @input="updateProperty('username', $event)"
    />
    <TextInput
      type="password"
      class="bg-minizo-base text-white"
      placeholder="password"
      @input="updateProperty('password', $event)"
    />
    <BaseButton @click="handler" class="rounded-none w-full shadow-none bg-minizo-green hover:opacity-70 transition delay-75">
      Authenticate
    </BaseButton>
  </BaseModal>
</template>