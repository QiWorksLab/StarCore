import json, sys
# 平台统一接收更新；不要再调用 getUpdates 或自行配置 Webhook。
# BOT_TOKEN 由环境变量注入；标准输出 JSON 可调用 Telegram 方法。
for line in sys.stdin:
    update = json.loads(line)
    message = update.get("message", {})
    if message.get("text"):
        print(json.dumps({"method":"sendMessage","params":{"chat_id":message["chat"]["id"],"text":"收到："+message["text"]}}, ensure_ascii=False), flush=True)
