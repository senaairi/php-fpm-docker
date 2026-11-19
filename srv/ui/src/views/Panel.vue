<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { Timer } from '@lucide/vue'
import { Api } from '@/api'

const route = useRoute()
const currentYear = new Date().getFullYear()
const currentSection = computed(() => {
  if (route.name === 'services') return 'services'
  if (route.name === 'container') return 'container'
  return 'timer'
})
</script>

<template>
  <main class="panel-shell">
    <header class="topbar">
      <RouterLink class="brand" :to="{ name: 'timer' }" aria-label="PHP-FPM 管理首页">
        <span class="brand-icon"><Timer :size="18" /></span>
        <span>PHP-FPM</span>
      </RouterLink>

      <nav class="primary-nav" aria-label="主导航">
        <RouterLink
          class="nav-item"
          :class="{ active: currentSection === 'timer' }"
          :to="{ name: 'timer' }"
        >
          定时器
        </RouterLink>
        <RouterLink
          class="nav-item"
          :class="{ active: currentSection === 'services' }"
          :to="{ name: 'services' }"
        >
          服务
        </RouterLink>
        <RouterLink
          class="nav-item"
          :class="{ active: currentSection === 'container' }"
          :to="{ name: 'container' }"
        >
          容器控制
        </RouterLink>
      </nav>

      <div class="topbar-meta">
        <span class="connection-dot" aria-hidden="true"></span>
        <span>已连接</span>
        <span class="topbar-separator"></span>
        <span class="endpoint">{{ Api.baseUrl }}</span>
      </div>
    </header>

    <div class="panel-content">
      <RouterView />
    </div>

    <footer class="panel-footer">
      <span>PHP-FPM 管理面板</span>
      <span>{{ currentYear }}</span>
    </footer>
  </main>
</template>

<style scoped>
.panel-shell {
  display: flex;
  min-height: 100vh;
  flex-direction: column;
  background: #f5f7f6;
  color: #202b25;
}

.topbar {
  display: flex;
  min-height: 58px;
  flex: none;
  align-items: stretch;
  gap: 38px;
  padding: 0 32px;
  border-bottom: 1px solid #dce3df;
  background: #fff;
}

.brand {
  display: inline-flex;
  flex: none;
  align-items: center;
  gap: 10px;
  color: #26362e;
  font-size: 14px;
  font-weight: 700;
  text-decoration: none;
}

.brand-icon {
  display: grid;
  width: 30px;
  height: 30px;
  place-items: center;
  border-radius: 6px;
  background: #e7f1eb;
  color: #24664a;
}

.primary-nav {
  display: flex;
  align-items: stretch;
  gap: 4px;
}

.nav-item {
  display: inline-flex;
  align-items: center;
  padding: 0 18px;
  border-bottom: 2px solid transparent;
  color: #64716a;
  font-size: 13px;
  text-decoration: none;
}

.nav-item.active {
  border-bottom-color: #28714f;
  color: #245f43;
  font-weight: 650;
}

.topbar-meta {
  display: flex;
  min-width: 0;
  align-items: center;
  gap: 8px;
  margin-left: auto;
  color: #66746c;
  font-size: 12px;
}

.connection-dot {
  width: 7px;
  height: 7px;
  flex: none;
  border-radius: 50%;
  background: #2d9965;
}

.topbar-separator {
  width: 1px;
  height: 16px;
  margin: 0 4px;
  background: #dce3df;
}

.endpoint {
  overflow: hidden;
  max-width: 260px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.panel-content {
  min-width: 0;
  flex: 1;
}

.panel-footer {
  display: flex;
  min-height: 42px;
  flex: none;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 0 32px;
  border-top: 1px solid #dce3df;
  background: #fff;
  color: #8a958e;
  font-size: 11px;
}

@media (max-width: 760px) {
  .topbar {
    min-height: 54px;
    gap: 16px;
    padding: 0 16px;
  }

  .topbar-meta {
    max-width: 48%;
    gap: 6px;
  }

  .topbar-meta > span:nth-child(2),
  .topbar-separator {
    display: none;
  }

  .endpoint {
    max-width: 100%;
  }

  .panel-footer {
    padding: 0 16px;
  }
}
</style>
