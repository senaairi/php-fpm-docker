import { createRouter, createWebHashHistory } from 'vue-router'
import { Api } from '@/api'

const router = createRouter({
  history: createWebHashHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      component: () => import('@/views/Panel.vue'),
      children: [
        {
          path: '',
          name: 'timer',
          component: () => import('@/views/TimerPage.vue'),
        },
        {
          path: 'services',
          name: 'services',
          component: () => import('@/views/ServicePage.vue'),
        },
        {
          path: 'container',
          name: 'container',
          component: () => import('@/views/ContainerPage.vue'),
        },
      ],
    },
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/LoginPage.vue'),
    },
    {
      path: '/:pathMatch(.*)*',
      redirect: '/',
    },
  ],
})

router.beforeEach((to) => {
  if (!Api.isConfigured() && to.name !== 'login') {
    return { name: 'login' }
  }
  if (Api.isConfigured() && to.name === 'login') {
    return { name: 'timer' }
  }
  return true
})

export default router
