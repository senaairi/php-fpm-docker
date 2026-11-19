<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { CircleAlert, KeyRound, Server, Timer } from '@lucide/vue'
import { Api } from '@/api'

const router = useRouter()
const baseUrl = ref(Api.baseUrl)
const apiToken = ref(Api.apiToken)
const errorMessage = ref('')
const saving = ref(false)
const canSubmit = computed(() => baseUrl.value.trim() && apiToken.value.trim())

const submit = async () => {
  errorMessage.value = ''
  let parsedUrl: URL
  try {
    parsedUrl = new URL(baseUrl.value.trim())
  } catch {
    errorMessage.value = '请输入有效的 API 前置地址'
    return
  }
  if (!['http:', 'https:'].includes(parsedUrl.protocol)) {
    errorMessage.value = 'API 地址必须使用 HTTP 或 HTTPS'
    return
  }
  if (!apiToken.value.trim()) {
    errorMessage.value = '请输入 API token'
    return
  }

  saving.value = true
  try {
    Api.configure(parsedUrl.toString(), apiToken.value)
    await router.replace({ name: 'timer' })
  } catch {
    errorMessage.value = '保存连接配置失败，请检查浏览器存储权限'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <main class="login-shell">
    <section class="login-panel" aria-labelledby="login-title">
      <div class="brand-mark" aria-hidden="true">
        <Timer :size="20" />
      </div>
      <p class="eyebrow">PHP-FPM CONTROL</p>
      <h1 id="login-title">连接管理 API</h1>
      <form class="login-form" @submit.prevent="submit">
        <label for="api-url">API 前置 URL</label>
        <div class="input-wrap">
          <Server :size="17" aria-hidden="true" />
          <input
            id="api-url"
            v-model="baseUrl"
            type="url"
            placeholder="http://127.0.0.1:55666"
            autocomplete="url"
            required
          />
        </div>

        <label for="api-token">API token</label>
        <div class="input-wrap">
          <KeyRound :size="17" aria-hidden="true" />
          <input
            id="api-token"
            v-model="apiToken"
            type="password"
            placeholder="输入 PFS_API_TOKEN"
            autocomplete="current-password"
            required
          />
        </div>

        <p v-if="errorMessage" class="form-error" role="alert">
          <CircleAlert :size="16" aria-hidden="true" />
          {{ errorMessage }}
        </p>

        <button class="submit-button" type="submit" :disabled="!canSubmit || saving">
          {{ saving ? '正在连接…' : '连接' }}
        </button>
      </form>
    </section>
  </main>
</template>

<style scoped>
.login-shell {
  display: grid;
  min-height: 100vh;
  place-items: center;
  padding: 32px 20px;
  background: #f4f6f5;
}

.login-panel {
  width: min(100%, 420px);
  padding: 36px;
  border: 1px solid #dce3df;
  border-radius: 8px;
  background: #fff;
  box-shadow: 0 12px 36px rgb(24 47 37 / 7%);
}

.brand-mark {
  display: grid;
  width: 42px;
  height: 42px;
  place-items: center;
  border-radius: 8px;
  background: #e8f3ed;
  color: #24664a;
}

.eyebrow {
  margin: 24px 0 8px;
  color: #64736b;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0;
}

h1 {
  margin: 0;
  color: #1d2923;
  font-size: 24px;
  font-weight: 650;
}

.login-form {
  display: grid;
  gap: 10px;
}

.login-form label {
  margin-top: 8px;
  color: #35423b;
  font-size: 13px;
  font-weight: 600;
}

.input-wrap {
  display: flex;
  min-width: 0;
  height: 44px;
  align-items: center;
  gap: 10px;
  padding: 0 12px;
  border: 1px solid #cad4ce;
  border-radius: 5px;
  color: #738078;
}

.input-wrap:focus-within {
  border-color: #347957;
  box-shadow: 0 0 0 3px rgb(52 121 87 / 12%);
}

.input-wrap input {
  width: 100%;
  min-width: 0;
  border: 0;
  outline: 0;
  background: transparent;
  color: #1d2923;
  font: inherit;
  font-size: 14px;
}

.input-wrap input::placeholder {
  color: #98a39d;
}

.form-error {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  margin: 4px 0;
  color: #a53b32;
  font-size: 13px;
  line-height: 1.5;
}

.form-error :deep(svg) {
  flex: none;
  margin-top: 1px;
}

.submit-button {
  height: 44px;
  margin-top: 14px;
  border: 0;
  border-radius: 5px;
  background: #24664a;
  color: #fff;
  cursor: pointer;
  font: inherit;
  font-size: 14px;
  font-weight: 600;
}

.submit-button:hover:not(:disabled) {
  background: #1c543c;
}

.submit-button:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}

@media (max-width: 480px) {
  .login-panel {
    padding: 28px 22px;
  }
}
</style>
