# 参与贡献

## 分支与合并

1. 从 `main` 创建短期分支，例如 `feat/worker-config` 或 `fix/fpm-startup`。外部贡献者可以先 fork 仓库。
2. 向 `main` 提交 Pull Request，说明改动原因、验证办法，以及配置或镜像使用方式有没有变化。
3. 维护者通过 Squash merge 合并，将每个 PR 归为一条记录。合并后可删除分支。

提交前请运行与改动相关的检查。前端改动在 `srv/ui` 下运行 `pnpm build`。镜像或启动流程改动请构建镜像，再用 README 的 Compose 示例验证 PHP-FPM 和管理面板。涉及管理 API 时，请在启用 Swoole 的环境验证相关操作。

## 版本与发布

- Git 标签使用 `vMAJOR.MINOR.PATCH`，按语义化版本管理公开的配置格式、管理 API 和容器使用方式。
- `0.x` 用于初期开发，破坏兼容的改动必须写进发布说明。稳定后，破坏兼容时升主版本，兼容新增功能时升次版本，兼容修复时升补丁版本。
- 发布从 `main` 中已验证的提交创建 Git 标签和 GitHub Release，说明改动、升级步骤和已验证的 Docker 镜像标签。Docker 镜像可以继续使用 `YYYYMMDD-HHMM` 标签，请在 Release 中明确它与 Git 版本的对应关系。
- 已经发布的 Git 标签和对应的镜像版本应保持固定。
