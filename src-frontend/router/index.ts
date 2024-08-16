import { createRouter, createWebHistory } from 'vue-router'
import { useApiStore } from '@/stores/api.ts';

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'home',
      component: () => import('../views/DownloadView.vue')
    },
    {
      path: '/manager/:directory',
      name: 'manager',
      component: () => import('../views/ManagerView.vue')
    }
  ]
})

router.beforeEach( async (to, from, next) => {
  try {
    const apiStore = useApiStore();
    await apiStore.getAbout();
    next();
  } catch (error) {
    return next({ name: "home"});
  }
})

export default router
