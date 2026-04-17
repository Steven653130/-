# LLM 卡片字段回填执行步骤

## 第1步：上传脚本到服务器

在宝塔文件管理器或 SFTP 中，上传本地文件：
```
ai_agent/backfill_all_langs.sh
```
到服务器路径：
```
/www/wwwroot/petqaa.com/ai_agent/backfill_all_langs.sh
```

或者直接在宝塔终端执行下面命令（新建文件并粘贴脚本内容）：
```bash
cat > /www/wwwroot/petqaa.com/ai_agent/backfill_all_langs.sh << 'EOF'
#!/bin/bash
# [粘贴脚本完整内容]
EOF
```

---

## 第2步：给脚本执行权限

```bash
chmod +x /www/wwwroot/petqaa.com/ai_agent/backfill_all_langs.sh
```

---

## 第3步：快速验证（前台运行）

先测试小批量，确认脚本正常运行，**不要跳过这一步**：

```bash
cd /www/wwwroot/petqaa.com/ai_agent

bash backfill_all_langs.sh 10 1
```

**预期输出**：
```
[backfill-batch=0] offset=0 limit=10
checked_rows=10 updated_rows=10
```

看到类似输出就表示成功了。如果有错误，会在这里立即显示。

---

## 第4步：后台大批量回填（推荐）

假设你有 **3000 条** 待回填的记录：

```bash
cd /www/wwwroot/petqaa.com/ai_agent

nohup bash backfill_all_langs.sh 100 30 > backfill_full.log 2>&1 &
```

**参数说明**：
- `100`：每批处理 100 条记录
- `30`：处理 30 个批次 = 3000 条
- `> backfill_full.log 2>&1`：输出到日志文件
- `&`：后台运行

---

## 第5步：监控回填进度

实时查看日志：

```bash
tail -f /www/wwwroot/petqaa.com/ai_agent/backfill_full.log
```

**正常日志长这样**：
```
[backfill] Starting backfill loop
[backfill] Batch size: 100, Max batches: 30
[backfill-batch=0] offset=0 limit=100
checked_rows=100 updated_rows=98
checked_rows=100 updated_rows=100
...
```

按 `Ctrl+C` 退出日志查看（不会停止后台任务）。

---

## 第6步：检查进程状态

查看回填是否还在运行：

```bash
ps aux | grep backfill_all_langs.sh | grep -v grep
```

如果有输出说明还在运行，没有输出说明已完成或出错。

---

## 第7步：等待完成

**预计耗时**：
- 1000 条记录：30-45 分钟
- 3000 条记录：2-3 小时
- 10000 条记录：6-8 小时

你可以关闭 SSH 窗口，任务会继续在后台运行。稍后用 `tail -f` 重新连接检查进度。

---

## 第8步：验证数据质量

回填完成后，执行下面 SQL 查询验证：

```sql
SELECT 
  id, 
  llm_title_zh, 
  llm_title_en, 
  llm_title_fr,
  LEFT(llm_summary_zh, 50) as summary_zh_preview,
  LEFT(llm_summary_en, 50) as summary_en_preview
FROM articles 
WHERE llm_title_zh != '' 
LIMIT 10;
```

**检查清单**：
- [ ] `llm_title_en/fr/es/ar/ru` 都不为空
- [ ] `llm_title_en/fr/es/ar/ru` 中没有中文字符
- [ ] `llm_summary_*` 都有内容（可以用 `LEFT(..., 50)` 预览）

如果发现有空字段或中文混入，说明有些翻译失败了，可以：
```bash
# 查看失败详情
grep "skip invalid" /www/wwwroot/petqaa.com/ai_agent/backfill_full.log | head -20

# 针对某语言重新补填（以英文为例，失败的行）
cd /www/wwwroot/petqaa.com/ai_agent
bash backfill_all_langs.sh 50 30 2>&1 | grep -E "(checked_rows|skip invalid)"
```

---

## 第9步：进入第8步开发（详情页）

验证通过后，开始实现点击卡片显示完整多语正文+图片的详情页功能。

---

## 故障排查

**问题1：后台任务卡住**
```bash
# 强制停止
pkill -9 -f "backfill_all_langs.sh"

# 再从中断处重新开始（需要自己计算offset）
cd /www/wwwroot/petqaa.com/ai_agent
nohup bash backfill_all_langs.sh 100 30 > backfill_full.log 2>&1 &
```

**问题2：看不到日志输出**
```bash
# 检查文件大小
ls -lh /www/wwwroot/petqaa.com/ai_agent/backfill_full.log

# 查看最后100行
tail -100 /www/wwwroot/petqaa.com/ai_agent/backfill_full.log

# 或者用 grep 查看统计
grep "checked_rows" /www/wwwroot/petqaa.com/ai_agent/backfill_full.log | tail -20
```

**问题3：翻译超时很多**
```bash
# 改大超时时间和重试次数，重新运行
cd /www/wwwroot/petqaa.com/ai_agent
env ZHIPU_TIMEOUT_SECONDS=40 ZHIPU_MAX_RETRIES=5 bash backfill_all_langs.sh 50 30 > backfill_full.log 2>&1 &
```

---

## 快速命令清单

复制粘贴执行：

```bash
# 1. 进入目录
cd /www/wwwroot/petqaa.com/ai_agent

# 2. 给脚本执行权限
chmod +x backfill_all_langs.sh

# 3. 快速验证（必做）
bash backfill_all_langs.sh 10 1

# 4. 后台大批量回填（3000条）
nohup bash backfill_all_langs.sh 100 30 > backfill_full.log 2>&1 &

# 5. 实时监控日志
tail -f backfill_full.log

# 6. 查看统计
grep "checked_rows" backfill_full.log | tail -20
```
