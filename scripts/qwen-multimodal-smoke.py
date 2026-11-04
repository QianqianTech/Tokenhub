#!/usr/bin/env python3
"""
千问（阿里云百炼 DashScope）多模态冒烟测试。只用 Python 标准库。

用法：
  1. 在下面 QWEN_API_KEY 填入百炼 API Key（sk-...），或设置环境变量 QWEN_API_KEY。
  2. python3 scripts/qwen-multimodal-smoke.py                 # 默认：低成本项
     python3 scripts/qwen-multimodal-smoke.py --with-image    # 加万相生图（收费）
     python3 scripts/qwen-multimodal-smoke.py --with-video    # 加万相生视频（收费，耗时几分钟）
     python3 scripts/qwen-multimodal-smoke.py --region intl   # 国际站
     python3 scripts/qwen-multimodal-smoke.py --only chat,vision,tts,asr

A 部分直连百炼，请求体与 TokenHub dashscope 适配器（VendorRequestMapper）发出的格式一致，
用来确认 docs/deployment/vendor-adapters.md 第 7 节里的“未实测项”。

B 部分（可选）通过 TokenHub 调同样的能力：设置 TOKENHUB_URL、TOKENHUB_API_KEY，
并用 --th-* 参数指定你在后台配置的模型名。

产物（合成的音频、生成的图片/视频、完整响应）保存在 ./qwen-smoke-output/。
"""

import argparse, base64, json, os, struct, sys, time, uuid, zlib
import urllib.request, urllib.error

# ======== 在这里填 Key ========
QWEN_API_KEY = ""
# ==============================

HOSTS = {"cn": "https://dashscope.aliyuncs.com", "intl": "https://dashscope-intl.aliyuncs.com"}
OUT = os.path.join(os.getcwd(), "qwen-smoke-output")
RESULTS = []


# ---------- 工具函数 ----------
def http(method, url, key, body=None, headers=None, timeout=120, raw=False, auth="bearer"):
    h = {"Accept": "application/json"}
    if auth == "bearer":
        h["Authorization"] = "Bearer " + key
    data = None
    if isinstance(body, (bytes, bytearray)):
        data = bytes(body)
    elif body is not None:
        data = json.dumps(body, ensure_ascii=False).encode()
        h["Content-Type"] = "application/json"
    h.update(headers or {})
    req = urllib.request.Request(url, data=data, headers=h, method=method)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            content = r.read()
            return r.status, dict(r.headers), content if raw else _json(content)
    except urllib.error.HTTPError as e:
        content = e.read()
        return e.code, dict(e.headers), _json(content)
    except Exception as e:  # 网络错误
        return 0, {}, {"_error": repr(e)}


def _json(content):
    try:
        return json.loads(content)
    except Exception:
        return {"_raw": content[:2000].decode("utf-8", "replace") if isinstance(content, bytes) else content}


def stream_lines(url, key, body, headers=None, timeout=120):
    h = {"Authorization": "Bearer " + key, "Content-Type": "application/json", "Accept": "text/event-stream"}
    h.update(headers or {})
    req = urllib.request.Request(url, data=json.dumps(body).encode(), headers=h, method="POST")
    with urllib.request.urlopen(req, timeout=timeout) as r:
        for line in r:
            line = line.decode("utf-8", "replace").strip()
            if line.startswith("data:"):
                payload = line[5:].strip()
                if payload == "[DONE]":
                    return
                yield _json(payload.encode())


def dig(obj, path, default=None):
    for p in path.split("."):
        if isinstance(obj, list):
            try:
                obj = obj[int(p)]
            except (ValueError, IndexError):
                return default
        elif isinstance(obj, dict):
            obj = obj.get(p)
        else:
            return default
        if obj is None:
            return default
    return obj


def save(name, data):
    os.makedirs(OUT, exist_ok=True)
    path = os.path.join(OUT, name)
    with open(path, "wb" if isinstance(data, bytes) else "w") as f:
        f.write(data if isinstance(data, bytes) else json.dumps(data, ensure_ascii=False, indent=2))
    return path


def make_png(w, h, rgb):
    row = b"\x00" + bytes(rgb) * w
    raw = row * h
    def chunk(t, d):
        return struct.pack(">I", len(d)) + t + d + struct.pack(">I", zlib.crc32(t + d) & 0xFFFFFFFF)
    return b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", w, h, 8, 2, 0, 0, 0)) + chunk(b"IDAT", zlib.compress(raw)) + chunk(b"IEND", b"")


def run(name, fn):
    t = time.time()
    try:
        ok, detail = fn()
    except Exception as e:
        ok, detail = False, "异常: " + repr(e)
    status = "PASS" if ok is True else ("SKIP" if ok is None else "FAIL")
    RESULTS.append((name, status, round(time.time() - t, 1), detail))
    print(f"[{status}] {name} ({RESULTS[-1][2]}s) {detail}", flush=True)


def err(status, body):
    msg = dig(body, "error.message") or dig(body, "message") or dig(body, "output.message") or body.get("_error") or body.get("_raw") or ""
    code = dig(body, "error.code") or dig(body, "code") or dig(body, "output.code") or ""
    return f"HTTP {status} {code} {str(msg)[:300]}"


def poll_task(base, key, task_id, label, max_wait):
    deadline = time.time() + max_wait
    while time.time() < deadline:
        s, _, b = http("GET", f"{base}/api/v1/tasks/{task_id}", key)
        st = dig(b, "output.task_status", "")
        if st in ("SUCCEEDED", "FAILED", "CANCELED", "UNKNOWN"):
            return st, b
        print(f"    {label} {task_id} 状态 {st or s}，继续等待…", flush=True)
        time.sleep(8)
    return "TIMEOUT", {}


def download(url, name):
    s, _, data = http("GET", url, "", raw=True, auth=None, timeout=300)
    if s != 200 or not isinstance(data, bytes):
        return None
    return save(name, data)


# ---------- A. 直连百炼 ----------
def direct_tests(a, key):
    base = HOSTS[a.region]
    compat = base + "/compatible-mode/v1"
    state = {}

    def models():
        s, _, b = http("GET", compat + "/models", key)
        ids = [m.get("id") for m in (b.get("data") or [])]
        if s != 200:
            return False, err(s, b)
        save("models.json", b)
        return True, f"{len(ids)} 个模型（列表已保存 models.json）"

    def chat():
        s, _, b = http("POST", compat + "/chat/completions", key, {"model": a.chat_model, "messages": [{"role": "user", "content": "只回答数字：1+1="}]})
        text = dig(b, "choices.0.message.content", "")
        return (s == 200 and "2" in text), (f"回复 {text!r} usage={b.get('usage')}" if s == 200 else err(s, b))

    def stream():
        parts, usage = [], None
        for ev in stream_lines(compat + "/chat/completions", key, {"model": a.chat_model, "stream": True, "stream_options": {"include_usage": True}, "messages": [{"role": "user", "content": "用一句话介绍杭州"}]}):
            parts.append(dig(ev, "choices.0.delta.content", "") or "")
            usage = ev.get("usage") or usage
        text = "".join(parts)
        return (len(text) > 0), f"{len(parts)} 个分片，usage={usage}，内容 {text[:40]!r}"

    def tools():
        body = {"model": a.chat_model, "messages": [{"role": "user", "content": "北京今天天气怎么样？"}],
                "tools": [{"type": "function", "function": {"name": "get_weather", "description": "查询城市天气", "parameters": {"type": "object", "properties": {"city": {"type": "string"}}, "required": ["city"]}}}]}
        s, _, b = http("POST", compat + "/chat/completions", key, body)
        call = dig(b, "choices.0.message.tool_calls.0.function")
        return (s == 200 and call is not None), (f"调用 {call}" if s == 200 else err(s, b))

    def vision():
        png = base64.b64encode(make_png(64, 64, (220, 20, 20))).decode()
        body = {"model": a.vl_model, "messages": [{"role": "user", "content": [
            {"type": "image_url", "image_url": {"url": "data:image/png;base64," + png}},
            {"type": "text", "text": "这张图是什么颜色？只回答颜色。"}]}]}
        s, _, b = http("POST", compat + "/chat/completions", key, body)
        text = dig(b, "choices.0.message.content", "")
        return (s == 200 and "红" in text), (f"回复 {text!r}" if s == 200 else err(s, b))

    def embedding():
        s, _, b = http("POST", compat + "/embeddings", key, {"model": a.embed_model, "input": ["TokenHub 多模态测试"]})
        vec = dig(b, "data.0.embedding") or []
        return (s == 200 and len(vec) > 0), (f"维度 {len(vec)}" if s == 200 else err(s, b))

    def tts():
        # 与 VendorRequestMapper::cosyvoiceTts 一致
        body = {"model": a.tts_model, "input": {"text": "你好，这是 TokenHub 的语音合成测试。", "voice": a.voice, "format": "mp3"}}
        s, _, b = http("POST", base + "/api/v1/services/audio/tts/SpeechSynthesizer", key, body)
        url = dig(b, "output.audio.url")
        save("tts-response.json", b)
        if s != 200 or not url:
            return False, err(s, b) + "（完整响应见 tts-response.json；TokenHub 读取 output.audio.url）"
        path = download(url, "tts.mp3")
        if not path:
            return False, "拿到 output.audio.url 但下载失败"
        state["audio"] = path
        return True, f"output.audio.url 正常，usage={b.get('usage')}，已保存 {path}"

    def asr():
        audio = a.audio or state.get("audio")
        if not audio:
            return None, "没有音频（TTS 失败且未传 --audio）"
        mime = "audio/wav" if audio.lower().endswith(".wav") else "audio/mpeg"
        data = "data:" + mime + ";base64," + base64.b64encode(open(audio, "rb").read()).decode()
        # 与 VendorRequestMapper::qwenAsr 一致：input_audio 只有 data 字段
        body = {"model": a.asr_model, "messages": [{"role": "user", "content": [{"type": "input_audio", "input_audio": {"data": data}}]}], "stream": False}
        s, _, b = http("POST", compat + "/chat/completions", key, body)
        save("asr-response.json", b)
        text = dig(b, "choices.0.message.content", "")
        if s == 200 and text:
            return True, f"识别 {text!r}，usage={b.get('usage')}（TokenHub 读取 usage.seconds={dig(b, 'usage.seconds')}）"
        return False, err(s, b)

    def omni():
        # 音频理解：Qwen-Omni 只支持流式
        audio = a.audio or state.get("audio")
        if not audio:
            return None, "没有音频"
        fmt = "wav" if audio.lower().endswith(".wav") else "mp3"
        data = "data:;base64," + base64.b64encode(open(audio, "rb").read()).decode()
        body = {"model": a.omni_model, "stream": True, "stream_options": {"include_usage": True}, "modalities": ["text"],
                "messages": [{"role": "user", "content": [{"type": "input_audio", "input_audio": {"data": data, "format": fmt}}, {"type": "text", "text": "这段音频说了什么？"}]}]}
        parts, usage = [], None
        try:
            for ev in stream_lines(compat + "/chat/completions", key, body):
                parts.append(dig(ev, "choices.0.delta.content", "") or "")
                usage = ev.get("usage") or usage
        except urllib.error.HTTPError as e:
            return False, err(e.code, _json(e.read()))
        text = "".join(parts)
        return (len(text) > 0), f"回复 {text[:60]!r} usage={usage}"

    def image():
        # 与 VendorRequestMapper::wanImage 一致
        body = {"model": a.image_model, "input": {"messages": [{"role": "user", "content": [{"text": "一只橘猫坐在窗台上，水彩风格"}]}]}, "parameters": {"size": "1024*1024", "n": 1}}
        s, _, b = http("POST", base + "/api/v1/services/aigc/image-generation/generation", key, body, {"X-DashScope-Async": "enable"})
        tid = dig(b, "output.task_id")
        if not tid:
            return False, err(s, b)
        st, r = poll_task(base, key, tid, "生图", 300)
        save("image-task.json", r)
        url = dig(r, "output.choices.0.message.content.0.image") or dig(r, "output.results.0.url")
        if st != "SUCCEEDED" or not url:
            return False, f"任务 {st}：{err(200, r)}（详情 image-task.json）"
        path = download(url, "image.png")
        return True, f"usage={r.get('usage')}，已保存 {path}"

    def video():
        # 与 VendorRequestMapper::wanVideo 一致
        body = {"model": a.video_model, "input": {"prompt": "海浪拍打礁石，日落，电影感"}, "parameters": {"resolution": "720P", "duration": 5}}
        s, _, b = http("POST", base + "/api/v1/services/aigc/video-generation/video-synthesis", key, body, {"X-DashScope-Async": "enable"})
        tid = dig(b, "output.task_id")
        if not tid:
            return False, err(s, b)
        st, r = poll_task(base, key, tid, "生视频", 900)
        save("video-task.json", r)
        url = dig(r, "output.video_url")
        if st != "SUCCEEDED" or not url:
            return False, f"任务 {st}：{err(200, r)}（详情 video-task.json）"
        path = download(url, "video.mp4")
        secs = dig(r, "usage.output_video_duration") or dig(r, "usage.duration")
        return True, f"计费秒数={secs}（TokenHub 读 usage.output_video_duration|usage.duration），已保存 {path}"

    tests = [("models", models), ("chat", chat), ("stream", stream), ("tools", tools), ("vision", vision),
             ("embedding", embedding), ("tts", tts), ("asr", asr), ("omni", omni)]
    if a.with_image:
        tests.append(("image", image))
    if a.with_video:
        tests.append(("video", video))
    for name, fn in tests:
        if a.only and name not in a.only:
            continue
        run("百炼直连 / " + name, fn)


# ---------- B. 经 TokenHub ----------
def tokenhub_tests(a):
    base = os.environ.get("TOKENHUB_URL", "").rstrip("/")
    key = os.environ.get("TOKENHUB_API_KEY", "")
    if not base or not key:
        print("\n（未设置 TOKENHUB_URL / TOKENHUB_API_KEY，跳过经 TokenHub 的测试）")
        return
    print(f"\n== 经 TokenHub：{base} ==")

    def chat():
        s, h, b = http("POST", base + "/v1/chat/completions", key, {"model": a.th_chat, "messages": [{"role": "user", "content": "只回答数字：2+3="}]})
        return (s == 200), f"{dig(b, 'choices.0.message.content')!r} request_id={h.get('X-Request-Id') or h.get('x-request-id')}" if s == 200 else err(s, b)

    def vision():
        png = base64.b64encode(make_png(64, 64, (20, 20, 220))).decode()
        s, _, b = http("POST", base + "/v1/chat/completions", key, {"model": a.th_vl, "messages": [{"role": "user", "content": [
            {"type": "image_url", "image_url": {"url": "data:image/png;base64," + png}}, {"type": "text", "text": "什么颜色？只回答颜色。"}]}]})
        return (s == 200), dig(b, "choices.0.message.content") if s == 200 else err(s, b)

    def tts():
        s, h, data = http("POST", base + "/v1/audio/speech", key, {"model": a.th_tts, "input": "经 TokenHub 的语音合成测试。", "voice": a.voice}, raw=True)
        if s != 200 or not isinstance(data, bytes):
            return False, err(s, data if isinstance(data, dict) else {})
        return True, f"{len(data)} 字节 {h.get('Content-Type')}，已保存 {save('tokenhub-tts.mp3', data)}"

    def asr():
        audio = a.audio or os.path.join(OUT, "tokenhub-tts.mp3")
        if not os.path.exists(audio):
            return None, "没有音频"
        boundary = uuid.uuid4().hex
        parts = [f"--{boundary}\r\nContent-Disposition: form-data; name=\"model\"\r\n\r\n{a.th_asr}\r\n".encode(),
                 f"--{boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{os.path.basename(audio)}\"\r\nContent-Type: audio/mpeg\r\n\r\n".encode(),
                 open(audio, "rb").read(), f"\r\n--{boundary}--\r\n".encode()]
        s, _, b = http("POST", base + "/v1/audio/transcriptions", key, b"".join(parts), {"Content-Type": f"multipart/form-data; boundary={boundary}"})
        return (s == 200 and bool(b.get("text"))), (repr(b.get("text")) if s == 200 else err(s, b))

    def image():
        s, _, b = http("POST", base + "/v1/images/generations", key, {"model": a.th_image, "prompt": "一只橘猫坐在窗台上，水彩风格", "size": "1024x1024"})
        if s == 200:
            return bool(b.get("data")), f"同步返回 {len(b.get('data') or [])} 张"
        tid = b.get("task_id") or dig(b, "data.task_id") or b.get("id")
        if s != 202 or not tid:
            return False, err(s, b)
        return th_poll(base, key, tid, 300)

    def video():
        s, _, b = http("POST", base + "/v1/videos/generations", key, {"model": a.th_video, "prompt": "海浪拍打礁石，日落", "duration": 5, "resolution": "720p"})
        tid = b.get("task_id") or dig(b, "data.task_id") or b.get("id")
        if s != 202 or not tid:
            return False, err(s, b)
        return th_poll(base, key, tid, 900)

    tests = [("chat", a.th_chat, chat), ("vision", a.th_vl, vision), ("tts", a.th_tts, tts), ("asr", a.th_asr, asr)]
    if a.with_image:
        tests.append(("image", a.th_image, image))
    if a.with_video:
        tests.append(("video", a.th_video, video))
    for name, model, fn in tests:
        if a.only and name not in a.only:
            continue
        if not model:
            RESULTS.append(("TokenHub / " + name, "SKIP", 0, "未指定 --th-" + name))
            continue
        run("TokenHub / " + name, fn)


def th_poll(base, key, tid, max_wait):
    deadline = time.time() + max_wait
    while time.time() < deadline:
        s, _, b = http("GET", f"{base}/v1/tasks/{tid}", key)
        st = str(b.get("status") or dig(b, "data.status") or "")
        if st in ("succeeded", "failed", "cancelled", "expired"):
            save(f"tokenhub-task-{tid}.json", b)
            return st == "succeeded", f"任务 {st}，billing={dig(b, 'result.billing')}（详情 tokenhub-task-{tid}.json）"
        print(f"    TokenHub 任务 {tid} 状态 {st or s}，继续等待…", flush=True)
        time.sleep(8)
    return False, "等待超时"


def main():
    p = argparse.ArgumentParser(description="千问多模态冒烟测试")
    p.add_argument("--key", default="", help="百炼 API Key（也可填在脚本顶部或用环境变量 QWEN_API_KEY）")
    p.add_argument("--region", choices=["cn", "intl"], default="cn")
    p.add_argument("--only", default="", help="只跑这些项，逗号分隔：models,chat,stream,tools,vision,embedding,tts,asr,omni,image,video")
    p.add_argument("--with-image", action="store_true", help="测试万相生图（收费）")
    p.add_argument("--with-video", action="store_true", help="测试万相生视频（收费）")
    p.add_argument("--audio", default="", help="用自己的音频文件测 ASR（mp3/wav）")
    p.add_argument("--chat-model", default="qwen-plus")
    p.add_argument("--vl-model", default="qwen-vl-plus")
    p.add_argument("--embed-model", default="text-embedding-v4")
    p.add_argument("--tts-model", default="cosyvoice-v2")
    p.add_argument("--voice", default="longxiaochun_v2")
    p.add_argument("--asr-model", default="qwen3-asr-flash")
    p.add_argument("--omni-model", default="qwen-omni-turbo")
    p.add_argument("--image-model", default="wan2.6-t2i")
    p.add_argument("--video-model", default="wan2.5-t2v-preview")
    for n in ["chat", "vl", "tts", "asr", "image", "video"]:
        p.add_argument(f"--th-{n}", default="", help=f"TokenHub 后台配置的 {n} 模型名")
    a = p.parse_args()
    a.only = [x.strip() for x in a.only.split(",") if x.strip()]

    key = a.key or os.environ.get("QWEN_API_KEY", "") or QWEN_API_KEY
    if key:
        print(f"== 百炼直连（{HOSTS[a.region]}）==")
        direct_tests(a, key)
    else:
        print("未提供百炼 Key：在脚本顶部 QWEN_API_KEY 填写，或 --key / 环境变量 QWEN_API_KEY。")
    tokenhub_tests(a)

    if not RESULTS:
        sys.exit(2)
    print("\n================ 汇总 ================")
    for name, status, secs, detail in RESULTS:
        print(f"{status:<5} {name:<24} {secs:>6}s  {str(detail)[:100]}")
    os.makedirs(OUT, exist_ok=True)
    save("summary.json", [dict(zip(["name", "status", "seconds", "detail"], r)) for r in RESULTS])
    print(f"\n产物和完整响应：{OUT}")
    sys.exit(1 if any(r[1] == "FAIL" for r in RESULTS) else 0)


if __name__ == "__main__":
    main()
