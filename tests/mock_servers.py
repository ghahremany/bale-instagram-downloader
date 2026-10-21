#!/usr/bin/env python3
"""
Mock Bale Bot API + mock Instagram provider, used by tests/integration.php.

    python3 tests/mock_servers.py --bale-port 9099 --ig-port 9098 --log /tmp/mock.jsonl

Bale endpoints served under /bot<TOKEN>/<METHOD>; multipart uploads are accepted
and only their metadata is recorded. Every call is appended to the JSONL log so
the PHP test can assert what the bot actually did.
"""
import argparse
import json
import os
import re
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

LOG_LOCK = threading.Lock()
LOG_PATH = ""
MESSAGE_ID = [1000]


def log(entry):
    with LOG_LOCK:
        with open(LOG_PATH, "a", encoding="utf-8") as fh:
            fh.write(json.dumps(entry, ensure_ascii=False) + "\n")


def ok(result=None):
    return json.dumps({"ok": True, "result": result if result is not None else {}}).encode()


class BaleHandler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, *args):
        pass

    def _read_body(self):
        length = int(self.headers.get("Content-Length") or 0)
        raw = self.rfile.read(length) if length else b""
        ctype = self.headers.get("Content-Type", "")
        if "application/json" in ctype:
            try:
                parsed = json.loads(raw or b"{}")
            except json.JSONDecodeError:
                return {}
            return parsed if isinstance(parsed, dict) else {}
        params = {}
        if "multipart/form-data" in ctype:
            # keep just the field names and payload sizes
            boundary = ctype.split("boundary=")[-1].strip().encode()
            for part in raw.split(b"--" + boundary):
                m = re.search(rb'name="([^"]+)"', part)
                if not m:
                    continue
                name = m.group(1).decode()
                body = part.split(b"\r\n\r\n", 1)[-1].rsplit(b"\r\n", 1)[0]
                params[name] = f"<{len(body)} bytes>"
        else:
            from urllib.parse import parse_qs
            for key, values in parse_qs(raw.decode(errors="ignore")).items():
                params[key] = values[0]
        return params

    def do_POST(self):
        path = self.path.split("?")[0]
        match = re.match(r"^/bot([^/]+)/([A-Za-z]+)$", path)
        if not match:
            self.send_response(404)
            self.send_header("Content-Length", "0")
            self.end_headers()
            return
        method = match.group(2)
        params = self._read_body()
        if not isinstance(params, dict):
            params = {}
        log({"kind": "bale", "method": method, "params": {k: v for k, v in params.items() if k != "token"}})

        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        payload = ok({"message_id": 0})

        if method == "getMe":
            payload = ok({"id": 111222333, "username": "TestDownloaderBot", "first_name": "Test Bot"})
        elif method in ("sendMessage", "sendPhoto", "sendVideo", "sendAudio", "sendDocument"):
            MESSAGE_ID[0] += 1
            payload = ok({"message_id": MESSAGE_ID[0]})
        elif method == "getChatMember":
            payload = ok({"status": "member", "user": {"id": int(params.get("user_id", 0))}})
        elif method == "getWebhookInfo":
            payload = ok({"url": "", "pending_update_count": 0})
        elif method == "getUpdates":
            payload = ok([])

        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def do_GET(self):
        self.do_POST()


class InstagramHandler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, *args):
        pass

    def do_GET(self):
        log({"kind": "instagram", "path": self.path})
        if "fail=1" in self.path or "failpost" in self.path:
            payload = json.dumps({"ok": False, "message": "post not found"}).encode()
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(payload)))
            self.end_headers()
            self.wfile.write(payload)
            return
        base = os.environ.get("MOCK_MEDIA_BASE", "http://127.0.0.1:9098/media")
        body = {
            "ok": True,
            "result": {
                "ok": True,
                "title": "تست پست اینستاگرام",
                "author": "test_account",
                "cover": f"{base}/cover.jpg",
                "files": [
                    {"type": "video", "url": f"{base}/video.mp4", "quality": "1080p", "ext": "mp4"},
                    {"type": "audio", "url": f"{base}/audio.mp3", "quality": "128kbps", "ext": "mp3"},
                ],
            },
        }
        payload = json.dumps(body, ensure_ascii=False).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)


def media(kind):
    # small deterministic payloads
    return {
        "mp4": b"\x00\x00\x00\x18ftypmp42" + b"\x11" * 2048,
        "mp3": b"ID3\x03\x00\x00\x00" + b"\x22" * 1024,
        "jpg": b"\xff\xd8\xff\xe0" + b"\x33" * 512 + b"\xff\xd9",
    }[kind]


class MediaHandler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, *args):
        pass

    def do_GET(self):
        m = re.search(r"/media/(\w+)\.(\w+)", self.path)
        if not m:
            self.send_response(404)
            self.send_header("Content-Length", "0")
            self.end_headers()
            return
        data = media(m.group(2))
        self.send_response(200)
        self.send_header("Content-Type", "application/octet-stream")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)


def serve(handler, port):
    server = ThreadingHTTPServer(("127.0.0.1", port), handler)
    server.daemon_threads = True
    threading.Thread(target=server.serve_forever, daemon=True).start()
    return server


def main():
    global LOG_PATH
    parser = argparse.ArgumentParser()
    parser.add_argument("--bale-port", type=int, default=9099)
    parser.add_argument("--ig-port", type=int, default=9098)
    parser.add_argument("--log", default="/tmp/mock.jsonl")
    args = parser.parse_args()
    LOG_PATH = args.log
    open(LOG_PATH, "w", encoding="utf-8").close()
    os.environ["MOCK_MEDIA_BASE"] = f"http://127.0.0.1:{args.ig_port}/media"
    bale = serve(BaleHandler, args.bale_port)
    ig = serve(InstagramHandler, args.ig_port)
    media_srv = serve(MediaHandler, args.ig_port + 100)
    # media URLs must point at the media server
    os.environ["MOCK_MEDIA_BASE"] = f"http://127.0.0.1:{args.ig_port + 100}/media"
    print(f"mock bale on :{args.bale_port}, provider on :{args.ig_port}, media on :{args.ig_port + 100}")
    try:
        while True:
            time.sleep(1)
    except KeyboardInterrupt:
        for srv in (bale, ig, media_srv):
            srv.shutdown()


if __name__ == "__main__":
    main()
