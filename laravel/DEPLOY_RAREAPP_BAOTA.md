# VoiceHub Laravel + /rareapp 部署（宝塔）

目标：不跑 `3000`，不走反向代理，直接访问 `https://你的域名/rareapp/`。

现在 Laravel 的行为：

1. 访问 `/` 会自动 `302` 跳转到 `/rareapp/`
2. 健康检查地址改为 `/health`
3. `/rareapp/*` 会优先读取 `laravel/public/rareapp` 下的静态文件，不存在则回退到 `index.html`（SPA 路由可用）

## 1. 初始化 Laravel API

```bash
cd /www/wwwroot/voicehub.example.com/VoiceHub/laravel
cp .env.example .env
composer install --no-dev --optimize-autoloader
php artisan key:generate
```

在 `laravel/.env` 中至少配置：

```env
APP_ENV=production
APP_DEBUG=false
APP_BASE_PATH=/rareapp
JWT_SECRET=替换成强随机字符串

DB_CONNECTION=mysql
DATABASE_URL=mysql://user:pass@127.0.0.1:3306/voicehub?charset=utf8mb4
DB_URL="${DATABASE_URL}"
```

可选方案 A：网页安装向导（install.php，推荐空库首次部署）

先在宝塔 Nginx 里临时加入：

```nginx
location = /rareapp/install.php {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /www/wwwroot/voicehub.example.com/VoiceHub/laravel/public/install.php;
    fastcgi_param SCRIPT_NAME /install.php;
    fastcgi_param DOCUMENT_ROOT /www/wwwroot/voicehub.example.com/VoiceHub/laravel/public;
    fastcgi_param REQUEST_URI $request_uri;
    fastcgi_param QUERY_STRING $query_string;
    fastcgi_pass unix:/tmp/php-cgi-82.sock;
}
```

重载 Nginx 后访问：

`https://你的域名/rareapp/install.php`

填入 MySQL 与管理员信息即可自动：

1. 创建数据库（如不存在）  
2. 导入 `laravel/database/mysql/voicehub_schema.sql`  
3. 创建/更新管理员账号  
4. 写入 `laravel/.env`  
5. 创建安装锁 `laravel/storage/app/install.lock`

安装完成后请删除该 location（或限制 IP 访问）。

可选方案 B：手动导入 SQL

空 MySQL 库初始化（建表）：

```bash
cd /www/wwwroot/voicehub.example.com/VoiceHub
mysql -u root -p voicehub < laravel/database/mysql/voicehub_schema.sql
```

如果你需要从空库开始，建议同时参考：
- `laravel/MYSQL_INIT.md`

## 2. 生成前端静态文件

```bash
cd /www/wwwroot/voicehub.example.com/VoiceHub
DATABASE_URL="mysql://user:pass@127.0.0.1:3306/voicehub?charset=utf8mb4" \
NUXT_APP_BASE_URL=/rareapp/ \
NUXT_PUBLIC_API_BASE=/rareapp/api \
npm run generate
```

静态文件输出目录：`/www/wwwroot/voicehub.example.com/VoiceHub/.output/public`

发布到 Laravel 公共目录（推荐，最省事）：

```bash
cd /www/wwwroot/voicehub.example.com/VoiceHub
mkdir -p laravel/public/rareapp
rsync -a --delete .output/public/ laravel/public/rareapp/
```

## 3. 宝塔 Nginx 配置（可选）

如果你的站点根目录已经指向 `VoiceHub/laravel/public`，且标准 `try_files` 到 `index.php` 已启用，一般不需要再额外配置 `/rareapp` 的 alias。

你仍然可以使用下方专门的 Nginx `location`（性能更高一些）：

将站点 Nginx 配置中加入（按实际 PHP 版本调整 `fastcgi_pass`）：

```nginx
location = /rareapp {
    return 301 /rareapp/;
}

location = /rareapp/api {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /www/wwwroot/voicehub.example.com/VoiceHub/laravel/public/index.php;
    fastcgi_param SCRIPT_NAME /index.php;
    fastcgi_param DOCUMENT_ROOT /www/wwwroot/voicehub.example.com/VoiceHub/laravel/public;
    fastcgi_param REQUEST_URI $request_uri;
    fastcgi_param QUERY_STRING $query_string;
    fastcgi_pass unix:/tmp/php-cgi-82.sock;
}

location ^~ /rareapp/api/ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /www/wwwroot/voicehub.example.com/VoiceHub/laravel/public/index.php;
    fastcgi_param SCRIPT_NAME /index.php;
    fastcgi_param DOCUMENT_ROOT /www/wwwroot/voicehub.example.com/VoiceHub/laravel/public;
    fastcgi_param REQUEST_URI $request_uri;
    fastcgi_param QUERY_STRING $query_string;
    fastcgi_pass unix:/tmp/php-cgi-82.sock;
}

location ^~ /rareapp/ {
    alias /www/wwwroot/voicehub.example.com/VoiceHub/.output/public/;
    index index.html;
    try_files $uri $uri/ /rareapp/index.html;
}
```

## 4. 验证

1. 打开 `https://你的域名/`，应自动跳转到 `/rareapp/`。
2. 打开 `https://你的域名/rareapp/`，应返回前端页面。
3. 打开 `https://你的域名/rareapp/api/auth/verify`，未登录应返回 `401` JSON。
4. 打开 `https://你的域名/health`，应返回 `{"name":"VoiceHub Laravel API","status":"ok"}`。
5. 登录后在账号页测试头像上传/移除，头像 URL 应为 `/rareapp/api/user/avatar-file/...`。
