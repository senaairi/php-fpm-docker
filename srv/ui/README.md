# 管理面板

PHP-FPM Swoole 管理面板，基于 Vue 3、TypeScript、Vite、Vue Router、PrimeVue 和 Tailwind CSS。

完整项目架构、配置、API 和 Docker 构建说明见仓库根目录 [README](../../README.md) 与 [docs](../../docs/)。

## 本地开发

要求 Node.js 版本满足 `^22.18.0` 或 `>=24.12.0`。

```sh
pnpm install
pnpm dev
```

Vite 默认监听 `16658`。在 `/#/login` 配置浏览器可访问的 API 地址和相同的 `PFS_API_TOKEN`。

## 类型检查与构建

```sh
pnpm type-check
pnpm build
```

生产文件输出到 `dist/`。仓库 `build.sh` 会将其复制到 `rootfs/pfs/public`。
