#!/bin/bash
# 接收外部传入的 PUID 和 PGID 以解决权限问题 (默认为 33 www-data)
PUID=${PUID:-33}
PGID=${PGID:-33}

echo "====================================="
echo "启动 STRM Generator Tool..."
echo "当前映射 UID: $PUID, GID: $PGID"
echo "====================================="

# 修改 www-data 用户的 UID 和 GID 以匹配宿主机，防止生成的文件没有修改权限
groupmod -o -g "$PGID" www-data || true
usermod -o -u "$PUID" www-data || true

# 初始化 /app 目录
mkdir -p /app/logs /app/locks

# 如果是全新映射的数据目录，将代码复制进去
if [ ! -f /app/index.php ]; then
    echo "检测到空目录，正在初始化 Web 文件..."
    cp /defaults/index.php /app/
    cp /defaults/strm_generator.py /app/
fi

# 确保 www-data (即 apache) 拥有核心目录的读写执行权
chown -R www-data:www-data /app

# 执行 Docker 原始的启动指令 (如启动 Apache)
exec "$@"