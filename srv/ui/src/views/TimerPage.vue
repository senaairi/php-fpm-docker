<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { CircleAlert, Clock3, FileClock, RefreshCw } from '@lucide/vue'
import { Api } from '@/api'
import type { ApiResponse } from '@/api'

type TimerTask = {
  name: string
  type: 'tick' | 'time' | string
  time: string
  command: string
  last_exec: string
  last_logs: string
  status: 'wait' | 'exec' | string
}

type TimerExecution = {
  start: string
  end: string
  logs: string | null
}

const tasks = ref<TimerTask[]>([])
const loading = ref(true)
const refreshing = ref(false)
const listError = ref('')
const historyVisible = ref(false)
const historyTask = ref<TimerTask | null>(null)
const historyLogs = ref<TimerExecution[]>([])
const historyLoading = ref(false)
const historyError = ref('')
let refreshTimer: ReturnType<typeof setInterval> | undefined

const formatDate = (value: string): string => {
  if (!value) return '从未执行'
  const date = Api.dayjs(value)
  return date.isValid() ? date.format('YYYY-MM-DD HH:mm:ss') : value
}

const taskCountLabel = computed(() => `${tasks.value.length} 个任务`)

const scheduleLabel = (task: TimerTask): string => {
  if (task.type === 'tick') return `每 ${task.time} 秒`
  if (task.type === 'time') return `每天 ${task.time}`
  return task.time
}

const errorText = (error: unknown): string => {
  const value = error as { response?: { data?: { message?: string } }; message?: string }
  return value.response?.data?.message || value.message || '请求失败，请检查 API 地址和连接状态'
}

const loadTasks = (background = false): void => {
  if (background) refreshing.value = true
  else loading.value = true
  listError.value = ''

  Api.Request(
    { url: Api.path.timer.list, method: 'GET' },
    (response: ApiResponse) => {
      if (response.status !== 'success' || !Array.isArray(response.data)) {
        listError.value = response.message || '无法读取定时器列表'
        return
      }
      tasks.value = response.data as TimerTask[]
    },
    (error) => {
      listError.value = errorText(error)
    },
    () => {
      loading.value = false
      refreshing.value = false
    },
  )
}

const openHistory = (task: TimerTask): void => {
  historyTask.value = task
  historyLogs.value = []
  historyError.value = ''
  historyVisible.value = true
  historyLoading.value = true

  Api.Request(
    {
      url: Api.path.timer.history,
      method: 'GET',
      data: { name: task.name },
    },
    (response: ApiResponse) => {
      if (response.status !== 'success' || !Array.isArray(response.data)) {
        historyError.value = response.message || '无法读取执行历史'
        return
      }
      historyLogs.value = response.data as TimerExecution[]
    },
    (error) => {
      historyError.value = errorText(error)
    },
    () => {
      historyLoading.value = false
    },
  )
}

const closeHistory = (): void => {
  historyVisible.value = false
}

const handleDialogKeydown = (event: KeyboardEvent): void => {
  if (event.key === 'Escape') closeHistory()
}

onMounted(() => {
  loadTasks()
  refreshTimer = setInterval(() => loadTasks(true), 30_000)
  window.addEventListener('keydown', handleDialogKeydown)
})

onUnmounted(() => {
  if (refreshTimer) clearInterval(refreshTimer)
  window.removeEventListener('keydown', handleDialogKeydown)
})
</script>

<template>
  <section class="timer-page">
    <section class="workspace" aria-labelledby="page-title">
      <div class="page-heading">
        <div>
          <p class="eyebrow">任务管理</p>
          <h1 id="page-title">定时器</h1>
        </div>
        <div class="heading-actions">
          <span class="task-count">{{ taskCountLabel }}</span>
          <button
            class="icon-button"
            type="button"
            aria-label="刷新列表"
            title="刷新列表"
            :disabled="refreshing"
            @click="loadTasks(true)"
          >
            <RefreshCw :size="16" :class="{ spinning: refreshing }" />
          </button>
        </div>
      </div>

      <div v-if="listError" class="error-banner" role="alert">
        <CircleAlert :size="17" />
        <span>{{ listError }}</span>
        <button type="button" @click="loadTasks()">重试</button>
      </div>

      <div class="table-frame">
        <div class="table-scroll">
          <table>
            <thead>
              <tr>
                <th scope="col">名称</th>
                <th scope="col">命令</th>
                <th scope="col">最近执行日期</th>
                <th scope="col">日志</th>
                <th scope="col" class="history-heading">执行历史</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="loading">
                <td colspan="5" class="table-message">正在加载定时器…</td>
              </tr>
              <tr v-else-if="!listError && tasks.length === 0">
                <td colspan="5" class="table-message">暂无定时器</td>
              </tr>
              <template v-else>
                <tr v-for="task in tasks" :key="task.name">
                  <td>
                    <div class="task-name">{{ task.name }}</div>
                    <div class="task-schedule">
                      <Clock3 :size="13" />
                      {{ scheduleLabel(task) }}
                    </div>
                  </td>
                  <td>
                    <code class="command-cell">{{ task.command }}</code>
                  </td>
                  <td>
                    <span class="last-execution">{{ formatDate(task.last_exec) }}</span>
                    <span class="status-label" :class="task.status">
                      <span class="status-dot"></span>
                      {{ task.status === 'exec' ? '执行中' : '等待中' }}
                    </span>
                  </td>
                  <td>
                    <pre class="latest-log" :title="task.last_logs || '暂无日志'">{{
                      task.last_logs || '暂无日志'
                    }}</pre>
                  </td>
                  <td class="history-cell">
                    <button class="history-button" type="button" @click="openHistory(task)">
                      <FileClock :size="15" />
                      查看历史
                    </button>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <Teleport to="body">
      <div
        v-if="historyVisible"
        class="dialog-backdrop"
        @click.self="closeHistory"
      >
        <section
          class="history-dialog"
          role="dialog"
          aria-modal="true"
          aria-labelledby="history-title"
        >
          <header class="dialog-header">
            <div>
              <p class="eyebrow">执行记录</p>
              <h2 id="history-title">{{ historyTask?.name }} 的历史日志</h2>
            </div>
            <button
              class="icon-button close-button"
              type="button"
              aria-label="关闭"
              title="关闭"
              @click="closeHistory"
            >
              <span aria-hidden="true">×</span>
            </button>
          </header>

          <div class="history-content">
            <div v-if="historyLoading" class="dialog-message">正在加载执行历史…</div>
            <div v-else-if="historyError" class="dialog-error" role="alert">
              <CircleAlert :size="17" />
              {{ historyError }}
            </div>
            <div v-else-if="historyLogs.length === 0" class="dialog-message">
              暂无执行记录
            </div>
            <ol v-else class="history-list">
              <li v-for="(entry, index) in [...historyLogs].reverse()" :key="`${entry.start}-${index}`">
                <div class="history-entry-head">
                  <time :datetime="entry.start">{{ formatDate(entry.start) }}</time>
                  <span>结束 {{ formatDate(entry.end) }}</span>
                </div>
                <pre class="history-log">{{ entry.logs || '（无输出）' }}</pre>
              </li>
            </ol>
          </div>
        </section>
      </div>
    </Teleport>
  </section>
</template>

<style scoped>
.timer-page {
  min-height: 100%;
  background: #f5f7f6;
  color: #202b25;
}

.topbar {
  display: flex;
  min-height: 58px;
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

.connection-dot,
.status-dot {
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

.workspace {
  width: min(100% - 48px, 1280px);
  margin: 0 auto;
  padding: 34px 0 60px;
}

.page-heading {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 20px;
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

.heading-actions {
  display: flex;
  align-items: center;
  gap: 12px;
}

.task-count {
  color: #718078;
  font-size: 12px;
}

.icon-button {
  display: inline-grid;
  width: 34px;
  height: 34px;
  flex: none;
  place-items: center;
  border: 1px solid #d5ded9;
  border-radius: 5px;
  background: #fff;
  color: #52625a;
  cursor: pointer;
}

.icon-button:hover:not(:disabled) {
  border-color: #94b2a1;
  background: #f2f7f4;
  color: #24664a;
}

.icon-button:disabled {
  cursor: default;
  opacity: 0.65;
}

.spinning {
  animation: spin 0.9s linear infinite;
}

.table-frame {
  overflow: hidden;
  border: 1px solid #d8e0db;
  border-radius: 6px;
  background: #fff;
}

.table-scroll {
  overflow-x: auto;
}

table {
  width: 100%;
  border-collapse: collapse;
  table-layout: fixed;
  text-align: left;
}

thead {
  background: #f7f9f8;
}

th {
  height: 46px;
  padding: 0 18px;
  border-bottom: 1px solid #dce3df;
  color: #536159;
  font-size: 12px;
  font-weight: 650;
  white-space: nowrap;
}

td {
  height: 74px;
  padding: 12px 18px;
  border-bottom: 1px solid #e7ece9;
  color: #344139;
  font-size: 13px;
  vertical-align: middle;
}

tbody tr:last-child td {
  border-bottom: 0;
}

th:nth-child(1) {
  width: 16%;
}

th:nth-child(2) {
  width: 30%;
}

th:nth-child(3) {
  width: 20%;
}

th:nth-child(4) {
  width: 21%;
}

th:nth-child(5) {
  width: 13%;
}

.task-name {
  overflow: hidden;
  color: #25342c;
  font-weight: 600;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.task-schedule {
  display: flex;
  align-items: center;
  gap: 5px;
  margin-top: 6px;
  color: #7a8780;
  font-size: 11px;
}

.task-schedule :deep(svg) {
  flex: none;
}

.command-cell {
  display: -webkit-box;
  overflow: hidden;
  color: #347451;
  font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
  font-size: 12px;
  line-height: 1.45;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
}

.last-execution {
  display: block;
  color: #344139;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.status-label {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-top: 6px;
  color: #758179;
  font-size: 11px;
}

.status-label.exec {
  color: #9a6a1c;
}

.status-label.exec .status-dot {
  background: #d79a31;
}

.latest-log {
  display: -webkit-box;
  overflow: hidden;
  margin: 0;
  color: #59675f;
  font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
  font-size: 11px;
  line-height: 1.5;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
}

.history-heading,
.history-cell {
  text-align: center;
}

.history-button {
  display: inline-flex;
  min-height: 34px;
  align-items: center;
  justify-content: center;
  gap: 7px;
  padding: 0 10px;
  border: 1px solid #cbd8d0;
  border-radius: 5px;
  background: #fff;
  color: #2e6046;
  cursor: pointer;
  font: inherit;
  font-size: 12px;
  white-space: nowrap;
}

.history-button:hover {
  border-color: #8bae98;
  background: #f2f7f4;
}

.table-message {
  height: 150px;
  color: #7a8780;
  text-align: center;
}

.error-banner {
  display: flex;
  align-items: center;
  gap: 9px;
  margin-bottom: 14px;
  padding: 11px 13px;
  border: 1px solid #e7c9c6;
  border-radius: 5px;
  background: #fff8f7;
  color: #9b3c34;
  font-size: 13px;
}

.error-banner span {
  min-width: 0;
  overflow-wrap: anywhere;
}

.error-banner button {
  margin-left: auto;
  border: 0;
  background: transparent;
  color: inherit;
  cursor: pointer;
  font: inherit;
  font-weight: 650;
}

.dialog-backdrop {
  position: fixed;
  z-index: 1000;
  display: grid;
  overflow-y: auto;
  place-items: center;
  padding: 24px;
  background: rgb(20 31 25 / 42%);
  inset: 0;
}

.history-dialog {
  display: flex;
  width: min(100%, 760px);
  max-height: min(82vh, 760px);
  flex-direction: column;
  border: 1px solid #d7e0da;
  border-radius: 7px;
  background: #fff;
  box-shadow: 0 22px 64px rgb(16 35 24 / 22%);
}

.dialog-header {
  display: flex;
  flex: none;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding: 20px 22px;
  border-bottom: 1px solid #e0e7e3;
}

h2 {
  font-size: 17px;
  overflow-wrap: anywhere;
}

.close-button {
  font-size: 24px;
  line-height: 1;
}

.history-content {
  overflow: auto;
  min-height: 150px;
  padding: 18px 22px 22px;
}

.dialog-message {
  display: grid;
  min-height: 150px;
  place-items: center;
  color: #78857e;
  font-size: 13px;
}

.dialog-error {
  display: flex;
  align-items: flex-start;
  gap: 9px;
  padding: 12px;
  border: 1px solid #e7c9c6;
  border-radius: 5px;
  background: #fff8f7;
  color: #9b3c34;
  font-size: 13px;
}

.history-list {
  display: grid;
  gap: 16px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.history-list li {
  min-width: 0;
  padding-bottom: 16px;
  border-bottom: 1px solid #e7ece9;
}

.history-list li:last-child {
  padding-bottom: 0;
  border-bottom: 0;
}

.history-entry-head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 16px;
  margin-bottom: 9px;
  color: #68766e;
  font-size: 12px;
}

.history-entry-head time {
  color: #35453b;
  font-weight: 650;
  font-variant-numeric: tabular-nums;
}

.history-log {
  overflow: auto;
  max-height: 240px;
  margin: 0;
  padding: 12px;
  border: 1px solid #e4eae6;
  border-radius: 4px;
  background: #f7f9f8;
  color: #35443b;
  font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
  font-size: 12px;
  line-height: 1.6;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
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

  .workspace {
    width: calc(100% - 32px);
    padding-top: 26px;
  }

  .table-scroll {
    overflow-x: auto;
  }

  table {
    min-width: 900px;
  }

  .dialog-backdrop {
    align-items: end;
    padding: 12px;
  }

  .history-dialog {
    max-height: 88vh;
  }

  .dialog-header,
  .history-content {
    padding-right: 16px;
    padding-left: 16px;
  }
}
</style>
