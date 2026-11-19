<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { CircleAlert, Play, RefreshCw, RotateCw, Server, Square, Terminal } from '@lucide/vue'
import { Api } from '@/api'
import type { ApiResponse } from '@/api'

type ServiceSnapshot = {
  name: string
  command: string | string[]
  status: 'running' | 'restarting' | 'stopped' | 'error' | string
  pid: number | null
  started_at: string | null
  last_exit_at: string | null
  exit_code: number | null
  output_lines: Array<{ time: string; stream: 'stdout' | 'stderr'; text: string }>
  output_max_lines: number
}

type ConsoleEvent =
  | { type: 'output'; stream: 'stdout' | 'stderr'; data: string; time: string }
  | { type: 'status'; status: string; pid: number | null; time: string; message?: string }
  | { type: 'heartbeat_ack'; service: string; client_time?: string; time: string }

const services = ref<ServiceSnapshot[]>([])
const selectedName = ref('')
const outputLines = ref<Array<{ time: string; stream: 'stdout' | 'stderr'; text: string }>>([])
const loading = ref(true)
const refreshing = ref(false)
const errorMessage = ref('')
const socketState = ref<'connecting' | 'open' | 'closed' | 'error'>('closed')
const command = ref('')
const outputPanel = ref<HTMLElement | null>(null)
let socket: WebSocket | null = null
let heartbeatTimer: ReturnType<typeof setInterval> | undefined

const selectedService = computed(
  () => services.value.find((service) => service.name === selectedName.value) ?? null,
)

const connectionLabel = computed(() => {
  if (socketState.value === 'open') return '控制台已连接'
  if (socketState.value === 'connecting') return '正在连接控制台'
  return '控制台未连接'
})

const formatTime = (value: string | null): string => {
  if (!value) return ''
  const date = Api.dayjs(value)
  return date.isValid() ? date.format('HH:mm:ss') : value
}

const statusLabel = (status: string): string => {
  return {
    running: '运行中',
    restarting: '等待重启',
    stopped: '已停止',
    error: '错误',
  }[status] ?? status
}

const commandLabel = (command: string | string[]): string => {
  return Array.isArray(command) ? command.map((part) => JSON.stringify(part)).join(' ') : command
}

const errorText = (error: unknown): string => {
  const value = error as { response?: { data?: { message?: string } }; message?: string }
  return value.response?.data?.message || value.message || '请求失败，请检查 API 地址和连接状态'
}

const scrollOutput = async (): Promise<void> => {
  await nextTick()
  if (outputPanel.value) {
    outputPanel.value.scrollTop = outputPanel.value.scrollHeight
  }
}

const loadServices = (background = false): void => {
  if (background) refreshing.value = true
  else loading.value = true
  errorMessage.value = ''
  Api.Request(
    { url: Api.path.service.list, method: 'GET' },
    (response: ApiResponse) => {
      if (response.status !== 'success' || !Array.isArray(response.data)) {
        errorMessage.value = response.message || '无法读取服务列表'
        return
      }
      services.value = response.data as ServiceSnapshot[]
      if (!selectedName.value && services.value[0]) selectedName.value = services.value[0].name
      if (selectedName.value && !services.value.some((item) => item.name === selectedName.value)) {
        selectedName.value = services.value[0]?.name ?? ''
      }
    },
    (error) => {
      errorMessage.value = errorText(error)
    },
    () => {
      loading.value = false
      refreshing.value = false
    },
  )
}

const closeSocket = (): void => {
  if (heartbeatTimer) {
    clearInterval(heartbeatTimer)
    heartbeatTimer = undefined
  }
  if (socket) {
    socket.onclose = null
    socket.close()
    socket = null
  }
  socketState.value = 'closed'
}

const connectSocket = (name: string): void => {
  closeSocket()
  outputLines.value = []
  const service = services.value.find((item) => item.name === name)
  if (!service) return
  socketState.value = 'connecting'
  try {
    socket = new WebSocket(Api.webSocketUrl(Api.path.service.console, { service: name }))
    socket.onopen = () => {
      socketState.value = 'open'
      heartbeatTimer = setInterval(() => {
        if (socket?.readyState === WebSocket.OPEN) {
          socket.send(JSON.stringify({
            type: 'heartbeat',
            time: new Date().toISOString(),
          }))
        }
      }, 30_000)
    }
    socket.onmessage = (event) => {
      try {
        const message = JSON.parse(event.data) as ConsoleEvent | { type: 'snapshot'; data: ServiceSnapshot }
        if (message.type === 'snapshot') {
          outputLines.value = message.data.output_lines ?? []
          const index = services.value.findIndex((item) => item.name === name)
          if (index >= 0) services.value[index] = message.data
        } else if (message.type === 'output') {
          outputLines.value.push({
            time: message.time,
            stream: message.stream,
            text: message.data.replace(/\r\n/g, '\n').replace(/\r/g, '\n'),
          })
          const maxLines = service.output_max_lines || 200
          if (outputLines.value.length > maxLines) outputLines.value.splice(0, outputLines.value.length - maxLines)
          scrollOutput()
        } else if (message.type === 'status') {
          const current = services.value.find((item) => item.name === name)
          if (current) {
            current.status = message.status
            current.pid = message.pid
          }
          scrollOutput()
        } else if (message.type === 'heartbeat_ack') {
          return
        }
      } catch {
        outputLines.value.push({
          time: new Date().toISOString(),
          stream: 'stderr',
          text: String(event.data),
        })
      }
      scrollOutput()
    }
    socket.onerror = () => {
      if (heartbeatTimer) {
        clearInterval(heartbeatTimer)
        heartbeatTimer = undefined
      }
      socketState.value = 'error'
    }
    socket.onclose = () => {
      if (heartbeatTimer) {
        clearInterval(heartbeatTimer)
        heartbeatTimer = undefined
      }
      socketState.value = 'closed'
    }
  } catch {
    socketState.value = 'error'
  }
}

const performAction = (action: 'start' | 'stop' | 'restart'): void => {
  if (!selectedName.value) return
  Api.json(
    { url: Api.path.service.action, data: { name: selectedName.value, action } },
    (response: ApiResponse) => {
      if (response.status !== 'success') {
        errorMessage.value = response.message || '服务操作失败'
        return
      }
      const index = services.value.findIndex((item) => item.name === selectedName.value)
      if (index >= 0) services.value[index] = response.data as ServiceSnapshot
    },
    (error) => {
      errorMessage.value = errorText(error)
    },
  )
}

const sendCommand = (): void => {
  if (!socket || socket.readyState !== WebSocket.OPEN || !command.value) return
  socket.send(JSON.stringify({ type: 'input', data: `${command.value}\n` }))
  command.value = ''
}

watch(selectedName, (name) => {
  if (name) connectSocket(name)
})

onMounted(() => {
  loadServices()
})

onUnmounted(() => {
  closeSocket()
})
</script>

<template>
  <section class="service-page">
    <section class="workspace" aria-labelledby="service-title">
      <div class="page-heading">
        <div>
          <p class="eyebrow">进程管理</p>
          <h1 id="service-title">后台服务</h1>
        </div>
        <button class="icon-button" type="button" title="刷新服务列表" aria-label="刷新服务列表" :disabled="refreshing" @click="loadServices(true)">
          <RefreshCw :size="16" :class="{ spinning: refreshing }" />
        </button>
      </div>

      <div v-if="errorMessage" class="error-banner" role="alert">
        <CircleAlert :size="17" />
        <span>{{ errorMessage }}</span>
      </div>

      <div class="service-layout">
        <aside class="service-list">
          <div class="list-header">服务 <span>{{ services.length }}</span></div>
          <div v-if="loading" class="list-message">正在加载…</div>
          <div v-else-if="services.length === 0" class="list-message">暂无服务配置</div>
          <template v-else>
            <button
              v-for="service in services"
              :key="service.name"
              class="service-item"
              :class="{ selected: service.name === selectedName }"
              type="button"
              @click="selectedName = service.name"
            >
              <span class="service-item-top">
                <span class="service-name">{{ service.name }}</span>
                <span class="status-pill" :class="service.status">{{ statusLabel(service.status) }}</span>
              </span>
              <span class="service-command">{{ commandLabel(service.command) }}</span>
              <span v-if="service.pid" class="service-pid">PID {{ service.pid }}</span>
            </button>
          </template>
        </aside>

        <section v-if="selectedService" class="console-panel" aria-label="服务控制台">
          <header class="console-header">
            <div>
              <p class="eyebrow">{{ selectedService.name }}</p>
              <h2>控制台</h2>
            </div>
            <div class="console-actions">
              <span class="socket-status" :class="socketState">
                <span class="status-dot"></span>
                {{ connectionLabel }}
              </span>
              <button class="small-action" type="button" title="启动服务" @click="performAction('start')">
                <Play :size="14" />启动
              </button>
              <button class="small-action" type="button" title="重启服务" @click="performAction('restart')">
                <RotateCw :size="14" />重启
              </button>
              <button class="small-action danger" type="button" title="停止服务" @click="performAction('stop')">
                <Square :size="13" />停止
              </button>
            </div>
          </header>
          <div ref="outputPanel" class="console-output">
            <div v-if="outputLines.length === 0" class="console-empty">
              <Terminal :size="22" />
              <span>等待服务输出…</span>
            </div>
            <div v-for="(line, index) in outputLines" :key="`${line.time}-${index}`" class="console-line" :class="line.stream">
              <time>{{ formatTime(line.time) }}</time>
              <span class="stream-label">{{ line.stream === 'stderr' ? 'ERR' : 'OUT' }}</span>
              <span class="line-text">{{ line.text }}</span>
            </div>
          </div>
          <form class="console-input" @submit.prevent="sendCommand">
            <span class="prompt">›</span>
            <input v-model="command" :disabled="socketState !== 'open'" type="text" placeholder="输入命令并回车发送到进程 stdin" />
            <button type="submit" :disabled="socketState !== 'open' || !command.trim()" title="发送命令">发送</button>
          </form>
        </section>
        <section v-else class="console-panel empty-panel">
          <Server :size="24" />
          <p>请选择一个后台服务</p>
        </section>
      </div>
    </section>
  </section>
</template>

<style scoped>
.service-page { min-height: 100%; background: #f5f7f6; color: #202b25; }
.topbar { display: flex; min-height: 58px; align-items: stretch; gap: 38px; padding: 0 32px; border-bottom: 1px solid #dce3df; background: #fff; }
.brand { display: inline-flex; flex: none; align-items: center; gap: 10px; color: #26362e; font-size: 14px; font-weight: 700; text-decoration: none; }
.brand-icon { display: grid; width: 30px; height: 30px; place-items: center; border-radius: 6px; background: #e7f1eb; color: #24664a; }
.primary-nav { display: flex; align-items: stretch; gap: 4px; }
.nav-item { display: inline-flex; align-items: center; padding: 0 18px; border-bottom: 2px solid transparent; color: #64716a; font-size: 13px; text-decoration: none; }
.nav-item.active { border-bottom-color: #28714f; color: #245f43; font-weight: 650; }
.topbar-meta { display: flex; min-width: 0; align-items: center; gap: 8px; margin-left: auto; color: #66746c; font-size: 12px; }
.connection-dot, .status-dot { width: 7px; height: 7px; flex: none; border-radius: 50%; background: #2d9965; }
.topbar-separator { width: 1px; height: 16px; margin: 0 4px; background: #dce3df; }
.endpoint { overflow: hidden; max-width: 260px; text-overflow: ellipsis; white-space: nowrap; }
.workspace { width: min(100% - 48px, 1280px); margin: 0 auto; padding: 34px 0 60px; }
.page-heading { display: flex; align-items: flex-end; justify-content: space-between; gap: 20px; margin-bottom: 20px; }
.eyebrow { margin: 0 0 6px; color: #718078; font-size: 11px; font-weight: 700; }
h1, h2 { margin: 0; color: #1e2b24; font-weight: 650; }
h1 { font-size: 23px; } h2 { font-size: 17px; }
.icon-button { display: inline-grid; width: 34px; height: 34px; flex: none; place-items: center; border: 1px solid #d5ded9; border-radius: 5px; background: #fff; color: #52625a; cursor: pointer; }
.icon-button:hover:not(:disabled) { border-color: #94b2a1; background: #f2f7f4; color: #24664a; }
.icon-button:disabled { cursor: default; opacity: .65; }
.spinning { animation: spin .9s linear infinite; }
.error-banner { display: flex; align-items: center; gap: 9px; margin-bottom: 14px; padding: 11px 13px; border: 1px solid #e7c9c6; border-radius: 5px; background: #fff8f7; color: #9b3c34; font-size: 13px; }
.service-layout { display: grid; min-height: 610px; grid-template-columns: 280px minmax(0, 1fr); overflow: hidden; border: 1px solid #d8e0db; border-radius: 6px; background: #fff; }
.service-list { border-right: 1px solid #dce3df; background: #f8faf9; }
.list-header { display: flex; height: 48px; align-items: center; justify-content: space-between; padding: 0 16px; border-bottom: 1px solid #dce3df; color: #526159; font-size: 12px; font-weight: 700; }
.list-header span { color: #8a958e; font-weight: 500; }
.list-message { padding: 32px 16px; color: #87928b; font-size: 13px; text-align: center; }
.service-item { display: grid; width: 100%; gap: 7px; padding: 14px 16px; border: 0; border-bottom: 1px solid #e5ebe7; background: transparent; color: inherit; cursor: pointer; text-align: left; }
.service-item:hover { background: #f0f6f2; } .service-item.selected { background: #e7f1eb; box-shadow: inset 3px 0 #2c7652; }
.service-item-top { display: flex; min-width: 0; align-items: center; justify-content: space-between; gap: 8px; }
.service-name { overflow: hidden; color: #2b3b31; font-size: 13px; font-weight: 650; text-overflow: ellipsis; white-space: nowrap; }
.service-command { overflow: hidden; color: #748078; font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 11px; text-overflow: ellipsis; white-space: nowrap; }
.service-pid { color: #8b968f; font-size: 11px; }
.status-pill { flex: none; padding: 3px 6px; border-radius: 3px; background: #edf0ee; color: #738078; font-size: 10px; }
.status-pill.running { background: #e2f2e8; color: #28714f; } .status-pill.restarting { background: #fff2d9; color: #a36d1a; } .status-pill.error { background: #fbe9e7; color: #a43e35; }
.console-panel { display: flex; min-width: 0; flex-direction: column; background: #fff; }
.console-header { display: flex; min-height: 76px; align-items: center; justify-content: space-between; gap: 16px; padding: 16px 20px; border-bottom: 1px solid #dce3df; }
.console-actions { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: 7px; }
.socket-status { display: inline-flex; align-items: center; gap: 6px; margin-right: 5px; color: #7c8981; font-size: 11px; }
.socket-status.open .status-dot { background: #2d9965; } .socket-status.connecting .status-dot { background: #d79a31; } .socket-status.error .status-dot { background: #bd493e; }
.small-action { display: inline-flex; min-height: 30px; align-items: center; gap: 5px; padding: 0 8px; border: 1px solid #d5ded9; border-radius: 4px; background: #fff; color: #52625a; cursor: pointer; font: inherit; font-size: 11px; }
.small-action:hover { border-color: #94b2a1; background: #f2f7f4; color: #24664a; } .small-action.danger:hover { border-color: #d5a39e; background: #fff7f6; color: #a43e35; }
.console-output { flex: 1; overflow: auto; min-height: 0; padding: 15px 18px; background: #17201b; color: #d7e4da; font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 12px; line-height: 1.6; }
.console-empty { display: grid; height: 100%; min-height: 400px; place-items: center; align-content: center; gap: 10px; color: #718078; font-family: inherit; font-size: 12px; }
.console-line { display: grid; grid-template-columns: 64px 34px minmax(0, 1fr); gap: 8px; white-space: pre-wrap; overflow-wrap: anywhere; }
.console-line time { color: #74877a; } .stream-label { color: #82b597; font-size: 10px; } .console-line.stderr .stream-label { color: #dd907f; } .console-line.stderr .line-text { color: #f0b1a4; }
.console-input { display: flex; min-height: 48px; align-items: center; gap: 9px; padding: 0 14px; border-top: 1px solid #dce3df; background: #f8faf9; }
.prompt { color: #28714f; font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 20px; }
.console-input input { min-width: 0; flex: 1; border: 0; outline: 0; background: transparent; color: #314139; font: inherit; font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 12px; }
.console-input button { min-height: 30px; padding: 0 12px; border: 0; border-radius: 4px; background: #28714f; color: #fff; cursor: pointer; font: inherit; font-size: 12px; }
.console-input button:disabled { cursor: not-allowed; opacity: .5; }
.empty-panel { display: grid; place-items: center; align-content: center; gap: 10px; color: #839088; } .empty-panel p { margin: 0; font-size: 13px; }
@keyframes spin { to { transform: rotate(360deg); } }
@media (max-width: 800px) {
  .topbar { gap: 16px; padding: 0 16px; } .topbar-meta { max-width: 42%; } .topbar-meta > span:nth-child(2), .topbar-separator { display: none; }
  .workspace { width: calc(100% - 32px); padding-top: 26px; } .service-layout { grid-template-columns: 1fr; } .service-list { max-height: 240px; overflow: auto; border-right: 0; border-bottom: 1px solid #dce3df; }
  .console-header { align-items: flex-start; flex-direction: column; } .console-actions { justify-content: flex-start; } .console-output { min-height: 390px; }
}
</style>
