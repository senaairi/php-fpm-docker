import axios from 'axios'
import type { AxiosRequestConfig } from 'axios'
import HmacSHA256 from 'crypto-js/hmac-sha256'
import Base64 from 'crypto-js/enc-base64'
import Utf8 from 'crypto-js/enc-utf8'
import dayjs from 'dayjs'
import dayjsUtc from 'dayjs/plugin/utc'
import type { App } from 'vue'

export type ApiResponse<T = unknown> = {
  status: 'error' | 'success' | 'sign_check_failed'
  message: string
  data: T
}

type ApiCallback = (value: ApiResponse) => void
type ApiFailure = (value: unknown) => void

const BASE_URL_KEY = 'pfs_api_base_url'
const TOKEN_KEY = 'pfs_api_token'
const REQUEST_TTL_SECONDS = 60

const normalizeBaseUrl = (value: string) => value.trim().replace(/\/+$/, '')

const loadStorageValue = (key: string): string => {
  try {
    return localStorage.getItem(key) ?? ''
  } catch {
    return ''
  }
}

const encodePayload = (payload: unknown): string => {
  const json = JSON.stringify(payload ?? {})
  return Base64.stringify(Utf8.parse(json))
}

const createRequestId = (): string => {
  if (typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }
  if (typeof crypto.getRandomValues === 'function') {
    const bytes = new Uint8Array(16)
    crypto.getRandomValues(bytes)
    return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')
  }
  return `${Date.now()}-${Math.random().toString(16).slice(2)}`
}

const redirectToLogin = () => {
  if (location.hash !== '#/login') {
    location.hash = '/login'
  }
}

const Api = {
  dayjs,
  path: {
    timer: {
      list: '/api/timer/list',
      history: '/api/timer/history',
    },
    service: {
      list: '/api/service/list',
      action: '/api/service/action',
      console: '/ws/services',
    },
    container: {
      shutdown: '/api/container/shutdown',
    },
  },
  baseUrl: loadStorageValue(BASE_URL_KEY),
  apiToken: loadStorageValue(TOKEN_KEY),

  configure(baseUrl: string, apiToken: string): void {
    this.baseUrl = normalizeBaseUrl(baseUrl)
    this.apiToken = apiToken.trim()
    localStorage.setItem(BASE_URL_KEY, this.baseUrl)
    localStorage.setItem(TOKEN_KEY, this.apiToken)
  },

  clearCredentials(): void {
    this.baseUrl = ''
    this.apiToken = ''
    localStorage.removeItem(BASE_URL_KEY)
    localStorage.removeItem(TOKEN_KEY)
  },

  isConfigured(): boolean {
    return Boolean(this.baseUrl && this.apiToken)
  },

  signedParams(payload: unknown = {}): URLSearchParams {
    const requestId = createRequestId()
    const expiresAt = Math.floor(Date.now() / 1000) + REQUEST_TTL_SECONDS
    const data = encodePayload(payload)
    const sign = HmacSHA256(`${requestId}${expiresAt}${data}`, this.apiToken).toString()
    return new URLSearchParams({
      request_id: requestId,
      time: String(expiresAt),
      data,
      sign,
    })
  },

  webSocketUrl(path: string, payload: unknown = {}): string {
    const url = new URL(`${this.baseUrl}${path.startsWith('/') ? path : `/${path}`}`)
    url.protocol = url.protocol === 'https:' ? 'wss:' : 'ws:'
    this.signedParams(payload).forEach((value, key) => url.searchParams.set(key, value))
    return url.toString()
  },

  Request(
    options: AxiosRequestConfig,
    success: ApiCallback,
    fail?: ApiFailure,
    _finally?: () => void,
  ): void {
    if (!this.isConfigured()) {
      redirectToLogin()
      fail?.({ message: '请先配置 API 地址和 token' })
      _finally?.()
      return
    }

    const endpoint = String(options.url ?? '')

    axios({
      ...options,
      url: `${this.baseUrl}${endpoint.startsWith('/') ? endpoint : `/${endpoint}`}`,
      params: {
        ...(options.params ?? {}),
        ...Object.fromEntries(this.signedParams(options.data ?? {})),
      },
    })
      .then((result) => {
        const response = result.data as ApiResponse
        if (response?.status === 'sign_check_failed') {
          this.clearCredentials()
          redirectToLogin()
          return
        }
        success(response)
      })
      .catch((error: unknown) => {
        const response = (
          error as { response?: { data?: ApiResponse } }
        ).response?.data
        if (response?.status === 'sign_check_failed') {
          this.clearCredentials()
          redirectToLogin()
          return
        }
        fail?.(error)
      })
      .finally(() => _finally?.())
  },

  json(
    options: AxiosRequestConfig,
    success: ApiCallback,
    fail?: ApiFailure,
    _finally?: () => void,
  ): void {
    this.Request(
      {
        ...options,
        method: 'POST',
        headers: {
          ...(options.headers ?? {}),
          'Content-Type': 'application/json',
        },
      },
      success,
      fail,
      _finally,
    )
  },
}

Api.dayjs.extend(dayjsUtc)

export { Api }

export default {
  install(app: App): void {
    app.config.globalProperties.$api = Api
  },
}
