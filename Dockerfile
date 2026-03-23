FROM php:8.2-apache

# 安装 Python3 以及必要的系统工具
RUN apt-get update && apt-get install -y \
    python3 \
    procps \
    tzdata \
    && rm -rf /var/lib/apt/lists/*

# 设置默认时区
ENV TZ=Asia/Shanghai

# 将 Apache 的 Web 根目录重定向到 /app (方便用户映射)
ENV APACHE_DOCUMENT_ROOT /app
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf && \
    sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# 创建默认文件存储目录
RUN mkdir -p /defaults /app

# 拷贝你的核心代码到默认目录 (容器首次启动时会自动释放给用户)
COPY index.php /defaults/
COPY strm_generator.py /defaults/
COPY docker-entrypoint.sh /usr/local/bin/

# 赋予执行权限
RUN chmod +x /usr/local/bin/docker-entrypoint.sh && \
    chmod +x /defaults/strm_generator.py

WORKDIR /app

# 设置启动入口
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]