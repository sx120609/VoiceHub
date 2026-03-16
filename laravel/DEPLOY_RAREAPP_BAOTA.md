# VoiceHub Laravel + /rareapp 部署（宝塔）

目标：不跑 `3000`，不走反向代理，直接访问 `https://你的域名/rareapp/`。

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

执行迁移（如果你已经有旧库，先确认结构再执行）：

```bash
php artisan migrate --force
```

## 2. 生成前端静态文件

```bash
cd /www/wwwroot/voicehub.example.com/VoiceHub
DATABASE_URL="mysql://user:pass@127.0.0.1:3306/voicehub?charset=utf8mb4" \
NUXT_APP_BASE_URL=/rareapp/ \
NUXT_PUBLIC_API_BASE=/rareapp/api \
npm run generate
```

静态文件输出目录：`/www/wwwroot/voicehub.example.com/VoiceHub/.output/public`

## 3. 宝塔 Nginx 配置

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

1. 打开 `https://你的域名/rareapp/`，应返回前端页面。
2. 打开 `https://你的域名/rareapp/api/auth/verify`，未登录应返回 `401` JSON。
3. 登录后在账号页测试头像上传/移除，头像 URL 应为 `/rareapp/api/user/avatar-file/...`。
