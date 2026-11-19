<script setup lang="ts">
import { ref } from 'vue'
import { CircleAlert, Power, ShieldCheck } from '@lucide/vue'
import { Api } from '@/api'
import type { ApiResponse } from '@/api'

const submitting = ref(false)
const shutdownRequested = ref(false)
const errorMessage = ref('')

const shutdown = (): void => {
  if (submitting.value || shutdownRequested.value) return
  if (!window.confirm('确认关闭容器吗？计时器和后台服务都会停止。')) return

  submitting.value = true
  errorMessage.value = ''
  Api.json(
    { url: Api.path.container.shutdown, data: {} },
    (response: ApiResponse) => {
      if (response.status !== 'success') {
        errorMessage.value = response.message || '容器关闭请求失败'
        return
      }
      shutdownRequested.value = true
    },
    (error) => {
      const value = error as { response?: { data?: { message?: string } }; message?: string }
      errorMessage.value = value.response?.data?.message || '无法连接到管理 API'
    },
    () => {
      submitting.value = false
    },
  )
}
</script>

<template>
  <section class="container-page">
    <section class="workspace" aria-labelledby="container-title">
      <div class="page-heading">
        <div>
          <p class="eyebrow">运行环境</p>
          <h1 id="container-title">容器控制</h1>
        </div>
      </div>

      <section class="control-panel">
        <div class="control-icon" aria-hidden="true">
          <Power :size="22" />
        </div>
        <div class="control-copy">
          <h2>安全关闭容器</h2>
          <p>关闭前会停止计时器和后台服务，并执行配置中的关机脚本。</p>
        </div>
        <button class="shutdown-button" type="button" :disabled="submitting || shutdownRequested" @click="shutdown">
          <Power :size="16" />
          {{ shutdownRequested ? '关闭已触发' : submitting ? '正在提交…' : '关闭容器' }}
        </button>
      </section>

      <div v-if="shutdownRequested" class="notice success" role="status">
        <ShieldCheck :size="17" />
        <span>关闭请求已接收，容器正在安全停止。</span>
      </div>
      <div v-if="errorMessage" class="notice error" role="alert">
        <CircleAlert :size="17" />
        <span>{{ errorMessage }}</span>
      </div>
    </section>
  </section>
</template>

<style scoped>
.container-page {
  min-height: 100%;
  background: #f5f7f6;
  color: #202b25;
}

.workspace {
  width: min(100% - 48px, 1280px);
  margin: 0 auto;
  padding: 34px 0 60px;
}

.page-heading {
  margin-bottom: 20px;
}

.eyebrow {
  margin: 0 0 6px;
  color: #718078;
  font-size: 11px;
  font-weight: 700;
}

h1,
h2 {
  margin: 0;
  color: #1e2b24;
  font-weight: 650;
}

h1 {
  font-size: 23px;
}

h2 {
  font-size: 16px;
}

.control-panel {
  display: flex;
  min-height: 132px;
  align-items: center;
  gap: 16px;
  padding: 24px;
  border: 1px solid #d8e0db;
  border-radius: 6px;
  background: #fff;
}

.control-icon {
  display: grid;
  width: 48px;
  height: 48px;
  flex: none;
  place-items: center;
  border-radius: 8px;
  background: #fbe9e7;
  color: #a43e35;
}

.control-copy {
  min-width: 0;
  flex: 1;
}

.control-copy p {
  margin: 8px 0 0;
  color: #718078;
  font-size: 13px;
  line-height: 1.6;
}

.shutdown-button {
  display: inline-flex;
  min-height: 38px;
  flex: none;
  align-items: center;
  gap: 8px;
  padding: 0 14px;
  border: 1px solid #d5a39e;
  border-radius: 5px;
  background: #fff8f7;
  color: #a43e35;
  cursor: pointer;
  font: inherit;
  font-size: 13px;
  font-weight: 650;
}

.shutdown-button:hover:not(:disabled) {
  background: #fbe9e7;
}

.shutdown-button:disabled {
  cursor: not-allowed;
  opacity: 0.6;
}

.notice {
  display: flex;
  align-items: center;
  gap: 9px;
  margin-top: 14px;
  padding: 11px 13px;
  border-radius: 5px;
  font-size: 13px;
}

.notice.success {
  border: 1px solid #b8d9c3;
  background: #f0f9f3;
  color: #28714f;
}

.notice.error {
  border: 1px solid #e7c9c6;
  background: #fff8f7;
  color: #9b3c34;
}

@media (max-width: 700px) {
  .workspace {
    width: calc(100% - 32px);
    padding-top: 26px;
  }

  .control-panel {
    align-items: flex-start;
    flex-direction: column;
  }

  .shutdown-button {
    width: 100%;
    justify-content: center;
  }
}
</style>
