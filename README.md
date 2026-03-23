# STRM Generator - 自动化云盘 STRM 生成神器

这是一款专为 NAS (群晖/Unraid/绿联等) 和个人影音库玩家打造的自动化中间件。

它能将 AList (OpenList) 挂载的云盘资源自动映射并生成 `.strm` 直链文件到您的本地媒体库中，从而实现 Emby/Jellyfin/Plex 对网盘资源的“伪本地化”管理。

## ✨ 核心特性

- 🐋 **轻量级一键部署**：完全 Docker 化，内置基于 Web 的 UI 控制台。
- 🛡️ **企业级防风控机制**：内置线程隔离机制与仿生随机延迟，完美保护 115、阿里云盘等敏感账号免受封禁。
- ⚡ **快增模式 (追剧神器)**：在定时任务和常用目录中开启后，自动跳过本地已存在的资源夹，实现超高速增量生成，极大降低 API 请求。
- 🧹 **智能强力保洁**：当网盘上的视频被删除或失效后，系统自动清空本地配套的废弃 `.strm`、`.nfo` 和海报文件。**内置深度检测与白名单机制**，绝不误删刮削器 (TMM/Emby) 的元数据。
- ⏱️ **全生命周期任务调度**：内置独立 Python 守护进程，支持 7×24 小时的 Cron 计划任务，并能在页面上实时实现任务的“暂停 / 恢复 / 终止”。

## 🚀 部署指南 (Docker)

由于跨目录生成文件对权限要求极高，我们强烈建议使用 Docker Compose 部署本项目以保证环境的纯净。

### 1. 准备目录与配置

找一个存放容器配置的文件夹，例如 `/volume1/docker/strm/`，并在其中新建 `docker-compose.yml` 文件：

```
version: '3.8'
services:
  strm-generator:
    image: mafzle/strm-generator:latest 
    container_name: strm-generator
    environment:
      - PUID=1000  # 必填：宿主机的用户 UID (通过 id 命令查看)
      - PGID=1000  # 必填：宿主机的组 GID
      - TZ=Asia/Shanghai
    volumes:
      - ./data:/app               # 程序配置持久化目录
      - /volume1/video:/video     # 您的本地影音库真实路径
    ports:
      - "38080:80"                # 访问端口
    restart: unless-stopped
```

### 2. 启动服务

```
docker-compose up -d
```

### 3. 初始化与使用

- 打开浏览器访问：`http://您的IP:38080`
- 进入右上角 **配置**，填入相关信息。
- **关于路径的特别说明 (极其重要)**：

  如果您的宿主机映射是 `- /volume1/video:/video`，那么在工具配置界面的 **“Srtm 的本地储存路径”** 中，**必须填写容器内的虚拟路径** `/video/...`，绝对不能填写 `/volume1/video`。

*Developed & Maintained by [mafzle](https://github.com/mafzle "null")*