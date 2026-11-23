#!/usr/bin/env bash
set -euo pipefail
base=/www/wwwroot/StarCore
php=/www/server/php/84/bin/php
run_reset() { "$php" -c "$base/data/cli.ini" "$base/reset-initial.php" "$@"; }
run_password() { "$php" -c "$base/data/cli.ini" "$base/reset-admin-password.php"; }
help_text() {
 echo 'StarCore SSH 管理工具'
 echo 'starcore                  打开交互菜单'
 echo 'starcore --check          只读环境检查'
 echo 'starcore --reset-password 随机重置超级管理员密码（交互确认）'
 echo 'starcore --yes            全新恢复并清空本站业务数据（直接执行）'
}
confirm_password() {
 local answer
 read -r -p '重置密码并使旧登录失效？输入 RESET 确认：' answer || return 0
 if [[ "$answer" == RESET ]]; then run_password; else echo '已取消。'; fi
}
confirm_fresh() {
 local answer
 echo '将永久清空本站账号、机器人、项目、日志、专用数据库和缓存，恢复初版代码；业务编号从 1 开始。'
 read -r -p '输入 StarCore 确认全新恢复：' answer || return 0
 if [[ "$answer" == StarCore ]]; then run_reset --yes; else echo '已取消。'; fi
}
case "${1:-}" in
 --help|-h) help_text; exit 0 ;;
 --check) run_reset --check; exit ;;
 --yes) run_reset --yes; exit ;;
 --reset-password)
  [[ -t 0 ]] || { echo '请在 SSH 交互终端操作。' >&2; exit 1; }
  [[ "$EUID" -eq 0 ]] || { echo '请使用 sudo starcore。' >&2; exit 1; }
  confirm_password; exit ;;
 "") ;;
 *) help_text; exit 2 ;;
esac
[[ -t 0 ]] || { help_text; exit 0; }
[[ "$EUID" -eq 0 ]] || { echo '请使用 sudo starcore。' >&2; exit 1; }
while true; do
 echo
 echo '====== StarCore SSH 管理工具 ======'
 echo '1) 检查恢复环境（只读）'
 echo '2) 随机重置超级管理员密码'
 echo '3) 全新恢复（清空本站所有业务数据）'
 echo '0) 退出'
 read -r -p '请选择：' choice || exit 0
 case "$choice" in
  1) run_reset --check || echo '检查失败，请查看上方原因。' ;;
  2) confirm_password || echo '密码重置失败，请查看上方原因。' ;;
  3) confirm_fresh || echo '恢复失败，请查看上方原因。' ;;
  0) exit 0 ;;
  *) echo '无效选项。' ;;
 esac
done
