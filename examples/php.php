<?php
// 平台通过 stdin 按行传入 Telegram Update；BOT_TOKEN 从环境变量读取。
while (($line = fgets(STDIN)) !== false) {
 $u=json_decode($line,true);$m=$u['message']??[];
 if(isset($m['text']))echo json_encode(['method'=>'sendMessage','params'=>['chat_id'=>$m['chat']['id'],'text'=>'收到：'.$m['text']]],JSON_UNESCAPED_UNICODE)."\n";
}
