package main
import ("bufio"; "encoding/json"; "fmt"; "os")
func main() {
 scanner:=bufio.NewScanner(os.Stdin)
 scanner.Buffer(make([]byte,65536),2097152)
 for scanner.Scan() {
  var u map[string]interface{}
  if json.Unmarshal(scanner.Bytes(),&u)!=nil {continue}
  m,ok:=u["message"].(map[string]interface{});if !ok {continue}
  text,ok:=m["text"].(string);if !ok {continue}
  chat,ok:=m["chat"].(map[string]interface{});if !ok {continue}
  out,_:=json.Marshal(map[string]interface{}{"method":"sendMessage","params":map[string]interface{}{"chat_id":chat["id"],"text":"收到："+text}})
  fmt.Println(string(out))
 }
}
