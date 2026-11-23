# StarCore 初始版本恢复工具

当前仅已创建、检查工具；没有执行清空，没有删除原有文件，也没有停止原有服务。

检查命令（不修改程序、不清空数据）：

```bash
sudo /www/wwwroot/StarCore/reset-initial.sh --check
```

执行一键恢复（永久清空后恢复首版程序）：

```bash
sudo /www/wwwroot/StarCore/reset-initial.sh --yes
```

恢复基线：第一次源码提交 2446a3f25d2411c375903ccf2a184b3a14bef7b7，ThinkPHP 8.1.4。基线包含 vendor，位于 /www/backup/StarCore-initial，执行时不需要联网下载依赖。

执行时停止本站 PHP 守护与残留旧守护，临时关闭本站 HTTP 请求；恢复初始代码，并清空站点内项目、上传、日志、发布记录、账号、Token、配置、现有 Git 历史以及 Redis DB 7 中本站 sc: 键。删除并重建专用 starcore 数据库，包含表、视图、触发器、过程和事件；重建初始库表与 admin 账号。自增编号重置，admin 初始 ID 为 1，其余新增记录从初始编号开始。

数据库和各表字符集为 utf8mb4，排序规则统一为 utf8mb4_unicode_ci。这里的排序规则不等于记录显示顺序；记录编号也会另行重置。

保留程序恢复所必需的数据库/Redis连接凭据、CLI配置、宝塔根目录 .user.ini 和恢复工具；Token加密密钥重新生成。旧登录凭据全部失效，新 admin 密码会在执行完成时显示，同时写入 data/initial-login.txt。平台域名设置清空，首次登录重新识别当前访问域名。

不会删除 /www/wwwroot/StarCore 之外的直接管理原目录；不会清空其他数据库、其他 Redis 命名空间或其他站点；不会撤销 Telegram 官方 Token。

若恢复中途失败，工具会报错；数据清空并非可回滚事务。不要将 --check 通过理解为已完成破坏性恢复测试。没有为验证工具而清空真实站点。
