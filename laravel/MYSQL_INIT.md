# VoiceHub MySQL 空库初始化

适用场景：你使用的是 **全新空 MySQL 数据库**。

## 1. 创建数据库

```sql
CREATE DATABASE IF NOT EXISTS voicehub
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;
```

## 2. 导入 VoiceHub 表结构

```bash
cd /www/wwwroot/voicehub.example.com/VoiceHub
mysql -u root -p voicehub < laravel/database/mysql/voicehub_schema.sql
```

## 3. 配置 Laravel 数据库连接

编辑 `laravel/.env`：

```env
DB_CONNECTION=mysql
DATABASE_URL=mysql://voicehub:你的密码@127.0.0.1:3306/voicehub?charset=utf8mb4
DB_URL="${DATABASE_URL}"
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=voicehub
DB_USERNAME=voicehub
DB_PASSWORD=你的密码
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci
```

然后执行：

```bash
cd laravel
php artisan config:clear
php artisan cache:clear
```

## 4. 创建第一个管理员账号

先生成密码哈希：

```bash
php -r "echo password_hash('Admin@123456', PASSWORD_BCRYPT), PHP_EOL;"
```

把输出的哈希填进下面 SQL：

```sql
INSERT INTO `User` (
  `createdAt`,`updatedAt`,`username`,`name`,`role`,`password`,`email`,`emailVerified`,`status`,`forcePasswordChange`
) VALUES (
  NOW(),NOW(),'admin','系统管理员','SUPER_ADMIN','$2y$10$替换为上面生成的哈希','admin@example.com',1,'active',0
);
```

## 5. 验证

1. 访问 `/rareapp/api/healthz`，应返回 `{"success":true,...}`  
2. 用管理员账号登录后台  
3. 进入系统设置页可正常读写数据库数据
