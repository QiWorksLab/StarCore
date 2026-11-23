# StarCore

StarCore 的全新初始化 Docker 版本（公开源码仓库）。

## 特点
- GitHub Actions 构建 Linux AMD64 镜像并发布至 GHCR。
- 不包含生产数据库、账户、机器人 Token、运行日志、项目数据或任何私人的 .env。
- 首次启动会连接指定 MySQL 并初始化 SC_ 表和初始管理员；请使用**全新的空数据库**，不要指向生产库。

## 部署
1. 准备 MySQL 数据库和专用账号，并确保容器可访问数据库。
2. 复制 `.env.example` 为 `.env`，填写 `DB_PASSWORD`；根据环境修改 `compose.yaml` 的数据库地址、库名和用户名。
3. 执行 `docker compose pull && docker compose up -d`。
4. 查看 `docker compose logs -f`，确认初始化完成后访问配置的 18081 端口。

镜像：`ghcr.io/agent-qingjiu/starcore:latest`。

## 数据
Docker volumes 保存配置、运行日志和项目文件；数据库由外部 MySQL 管理。删除 volumes 不可恢复数据。

## 构建
推送至 main 或手动运行 GitHub Actions 的 Docker 工作流即可构建。首次发布后需确认 GHCR 包权限和访问策略。

## 私人数据
本仓库不包含现有服务器的业务数据；私人迁移备份独立存放于私有 `StarCore_Data` 仓库。
