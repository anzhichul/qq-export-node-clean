import argparse
import base64
import ctypes
import hashlib
import hmac
import json
import os
import queue
import shutil
import socket
import subprocess
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

import pymysql

if os.name == "nt":
    import win32con
    import win32crypt
    import win32net
    import win32netcon
    from ctypes import wintypes

    LOGON_WITH_PROFILE = 0x00000001
    CREATE_UNICODE_ENVIRONMENT = 0x00000400

    class STARTUPINFOW(ctypes.Structure):
        _fields_ = [
            ("cb", wintypes.DWORD), ("lpReserved", wintypes.LPWSTR),
            ("lpDesktop", wintypes.LPWSTR), ("lpTitle", wintypes.LPWSTR),
            ("dwX", wintypes.DWORD), ("dwY", wintypes.DWORD),
            ("dwXSize", wintypes.DWORD), ("dwYSize", wintypes.DWORD),
            ("dwXCountChars", wintypes.DWORD), ("dwYCountChars", wintypes.DWORD),
            ("dwFillAttribute", wintypes.DWORD), ("dwFlags", wintypes.DWORD),
            ("wShowWindow", wintypes.WORD), ("cbReserved2", wintypes.WORD),
            ("lpReserved2", ctypes.POINTER(ctypes.c_byte)),
            ("hStdInput", wintypes.HANDLE), ("hStdOutput", wintypes.HANDLE),
            ("hStdError", wintypes.HANDLE),
        ]

    class PROCESS_INFORMATION(ctypes.Structure):
        _fields_ = [
            ("hProcess", wintypes.HANDLE), ("hThread", wintypes.HANDLE),
            ("dwProcessId", wintypes.DWORD), ("dwThreadId", wintypes.DWORD),
        ]


class WindowsProcess:
    def __init__(self, process_handle, thread_handle, pid):
        self.handle = process_handle
        self.thread_handle = thread_handle
        self.pid = int(pid)

    def poll(self):
        result = ctypes.windll.kernel32.WaitForSingleObject(self.handle, 0)
        if result == win32con.WAIT_TIMEOUT:
            return None
        code = wintypes.DWORD()
        ctypes.windll.kernel32.GetExitCodeProcess(self.handle, ctypes.byref(code))
        return int(code.value)


def request_json(url, token="", payload=None, timeout=30):
    data = None if payload is None else json.dumps(payload).encode("utf-8")
    headers = {"Accept": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    if data is not None:
        headers["Content-Type"] = "application/json"
    request = urllib.request.Request(url, data=data, headers=headers, method="POST" if data is not None else "GET")
    with urllib.request.urlopen(request, timeout=timeout) as response:
        result = json.loads(response.read().decode("utf-8"))
    if (
        not result.get("ok", False)
        and result.get("status") != "ok"
        and result.get("code") != 0
    ):
        raise RuntimeError(result.get("error") or result.get("message") or str(result))
    return result


SHARED_DIRS = ("node_modules", "native", "static", "worker")


class Agent:
    def __init__(self, config, config_path=None):
        self.config = config
        self.config_path = Path(config_path).resolve() if config_path else None
        self.cloud_url = str(config["cloud_url"]).rstrip("/")
        self.token = str(config["agent_token"])
        self.node_id = str(config["node_id"])
        self.name = str(config.get("name") or socket.gethostname())
        self.accounts = config.get("accounts", [])
        self.webui_credentials = {}
        self.processes = {}
        self.busy_accounts = set()
        self.assigned_accounts = dict(config.get("assigned_accounts") or {})
        self.message_write_lock = threading.Lock()
        configured_idle_timeout = int(config.get("idle_timeout_seconds", 600))
        self.idle_timeout = max(60, configured_idle_timeout) if configured_idle_timeout > 0 else 0
        configured_unlogged_timeout = int(config.get("unlogged_timeout_seconds", 180))
        self.unlogged_timeout = max(30, configured_unlogged_timeout) if configured_unlogged_timeout > 0 else 0
        self.max_active_accounts = max(1, int(config.get("max_active_accounts", 40)))
        self.slot_pool_enabled = bool(config.get("slot_pool_enabled", False))
        self.slot_pool_count = max(1, int(config.get("slot_pool_count", 10)))
        self.slot_pool_wait = max(0, int(config.get("slot_pool_wait_seconds", self.idle_timeout or 600)))
        self.message_store = dict(config.get("message_store") or {})
        self.message_store_enabled = bool(self.message_store.get("enabled"))
        self.message_store_host = str(self.message_store.get("db_host") or "")
        self.message_store_port = int(self.message_store.get("db_port") or 3306)
        self.message_store_user = str(self.message_store.get("db_user") or "")
        self.message_store_pass = str(self.message_store.get("db_pass") or "")
        self.message_store_name = str(self.message_store.get("db_name") or "")
        self.message_store_secret = str(self.message_store.get("secret") or "")
        self.message_store_bind = str(self.message_store.get("bind") or "127.0.0.1")
        self.message_store_port_listen = int(self.message_store.get("listen_port") or 3015)
        self.message_store_path = str(self.message_store.get("path") or "/napcat/event")
        self.message_store_server = None
        self.message_store_thread = None
        self.message_queue = queue.Queue(maxsize=100000)
        self.message_worker_thread = None
        self.message_store_db = None
        self.login_sync_threads = {}
        self.login_sync_enabled = bool(self.message_store.get("sync_on_login", True))
        self.private_history_limit = max(0, int(self.message_store.get("private_history_limit", 100)))
        self.sync_stop_before = max(30, int(self.message_store.get("sync_stop_before_seconds", 120)))
        self.oss_cfg = dict(config.get("oss") or {})
        self.oss_enabled = bool(self.oss_cfg.get("enabled") and self.oss_cfg.get("access_key_id"))
        self.oss_ak = str(self.oss_cfg.get("access_key_id") or "")
        self.oss_sk = str(self.oss_cfg.get("access_key_secret") or "")
        self.oss_endpoint = str(self.oss_cfg.get("endpoint") or "").rstrip("/")
        self.oss_bucket = str(self.oss_cfg.get("bucket") or "")
        self.oss_public_base = str(self.oss_cfg.get("public_base_url") or self.oss_endpoint).rstrip("/")
        self.oss_prefix = str(self.oss_cfg.get("prefix") or "collections").strip("/")
        self.oss_download_timeout = int(self.oss_cfg.get("download_timeout") or 45)
        timestamp = int(time.time())
        changed = False
        for account in self.accounts:
            if account.pop("password_md5", None) is not None:
                changed = True
            if account.pop("pending_login", None) is not None:
                changed = True
            command = account.get("launch_command")
            if account.get("profile_isolated") and isinstance(command, list) and "-q" in command:
                account["launch_command"] = command[:command.index("-q")]
                changed = True
            if account.get("profile_isolated"):
                runtime = Path(str(account.get("working_directory") or ""))
                webui_path = runtime / "config" / "webui.json"
                if webui_path.exists():
                    try:
                        webui = json.loads(webui_path.read_text(encoding="utf-8"))
                        if webui.get("autoLoginAccount"):
                            webui["autoLoginAccount"] = ""
                            webui_path.write_text(
                                json.dumps(webui, ensure_ascii=False, indent=2), encoding="utf-8"
                            )
                    except (OSError, ValueError):
                        pass
            if "managed" not in account:
                account["managed"] = True
                changed = True
            if not account.get("runtime_status"):
                account["runtime_status"] = "active" if int(account.get("http_port", 0)) > 0 else "idle_offline"
                changed = True
            if account.get("managed") and account.get("runtime_status") != "idle_offline" and not account.get("active_until"):
                account["last_activity"] = timestamp
                account["active_until"] = timestamp + self.idle_timeout
                changed = True
            if (
                account.get("managed")
                and account.get("slot_id")
                and not account.get("pooled")
                and not account.get("bound_at")
                and not account.get("was_online")
            ):
                account["bound_at"] = timestamp
                changed = True
        if changed:
            self.save_config()
        if self.message_store_enabled:
            self.ensure_message_tables()
            self.start_message_server()
        if self.slot_pool_enabled:
            threading.Thread(target=self.ensure_pool, daemon=True).start()

    @staticmethod
    def password_md5(password):
        return hashlib.md5(str(password).encode("utf-8")).hexdigest()

    @staticmethod
    def wait_for_http_service(url, timeout=60):
        parsed = urllib.parse.urlparse(str(url))
        host = parsed.hostname or "127.0.0.1"
        port = int(parsed.port or (443 if parsed.scheme == "https" else 80))
        deadline = time.time() + max(1, timeout)
        while time.time() < deadline:
            try:
                with socket.create_connection((host, port), timeout=2):
                    return True
            except OSError:
                time.sleep(1)
        return False

    @staticmethod
    def port_accepts_http(url, timeout=5):
        try:
            request_json(str(url).rstrip("/") + "/get_login_info", timeout=timeout)
            return True
        except Exception:
            return False

    @staticmethod
    def qr_image_from_url(qrcode_url):
        qrcode_url = str(qrcode_url or "").strip()
        if not qrcode_url:
            return ""
        return (
            "https://api.qrserver.com/v1/create-qr-code/?size=280x280&data="
            + urllib.parse.quote(qrcode_url, safe="")
        )

    @staticmethod
    def error_means_logged_in(error):
        text = str(error or "")
        return "已登录" in text or "无法重复登录" in text

    def save_config(self):
        if not self.config_path:
            return
        temporary = self.config_path.with_suffix(self.config_path.suffix + ".tmp")
        temporary.write_text(json.dumps(self.config, ensure_ascii=False, indent=2), encoding="utf-8")
        os.replace(temporary, self.config_path)

    @staticmethod
    def windows_username(uin):
        return ("AkaQQ" + str(uin))[:20]

    @staticmethod
    def protect_windows_password(password):
        encrypted = win32crypt.CryptProtectData(
            str(password).encode("utf-8"), "AkaQQ account", None, None, None,
            0x4,
        )
        return base64.b64encode(encrypted).decode("ascii")

    @staticmethod
    def unprotect_windows_password(value):
        _, decrypted = win32crypt.CryptUnprotectData(
            base64.b64decode(str(value)), None, None, None,
            0x4,
        )
        return decrypted.decode("utf-8")

    def ensure_windows_identity(self, account, runtime):
        if os.name != "nt":
            return
        username = str(account.get("windows_user") or self.windows_username(account["uin"]))
        encrypted = str(account.get("windows_password_dpapi") or "")
        if encrypted:
            password = self.unprotect_windows_password(encrypted)
        else:
            password = base64.urlsafe_b64encode(os.urandom(30)).decode("ascii").rstrip("=") + "!aA7"
            try:
                win32net.NetUserDel(None, username)
            except Exception:
                pass
            win32net.NetUserAdd(None, 1, {
                "name": username,
                "password": password,
                "priv": win32netcon.USER_PRIV_USER,
                "home_dir": "",
                "comment": "Aka云信 QQ 隔离账号 " + str(account["uin"]),
                "flags": win32netcon.UF_SCRIPT | win32netcon.UF_DONT_EXPIRE_PASSWD | win32netcon.UF_PASSWD_CANT_CHANGE,
                "script_path": "",
            })
            account["windows_user"] = username
            account["windows_password_dpapi"] = self.protect_windows_password(password)
        try:
            win32net.NetLocalGroupDelMembers(
                None, "Administrators", [{"domainandname": username}]
            )
        except Exception:
            pass
        subprocess.run(
            ["icacls", str(runtime), "/grant", f"{username}:(OI)(CI)F", "/T", "/C"],
            capture_output=True, creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0), check=False,
        )
        template = Path(str(self.config.get("napcat_template") or ""))
        for name in SHARED_DIRS:
            source = template / name
            if source.exists():
                subprocess.run(
                    ["icacls", str(source), "/grant", f"{username}:(OI)(CI)RX", "/T", "/C"],
                    capture_output=True, creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0), check=False,
                )
        self.save_config()

    @staticmethod
    def start_as_windows_user(username, password, command, cwd, environment):
        profile = Path(os.environ.get("SystemDrive", "C:") + "\\") / "Users" / username
        user_environment = dict(environment)
        user_environment.update({
            "USERNAME": username,
            "USERPROFILE": str(profile),
            "HOMEDRIVE": profile.drive,
            "HOMEPATH": str(profile)[len(profile.drive):],
            "APPDATA": str(profile / "AppData" / "Roaming"),
            "LOCALAPPDATA": str(profile / "AppData" / "Local"),
            "TEMP": str(profile / "AppData" / "Local" / "Temp"),
            "TMP": str(profile / "AppData" / "Local" / "Temp"),
        })
        environment_text = "\0".join(f"{key}={value}" for key, value in sorted(user_environment.items(), key=lambda item: item[0].upper())) + "\0\0"
        environment_buffer = ctypes.create_unicode_buffer(environment_text)
        command_buffer = ctypes.create_unicode_buffer(subprocess.list2cmdline([str(part) for part in command]))
        startup = STARTUPINFOW()
        startup.cb = ctypes.sizeof(startup)
        process_info = PROCESS_INFORMATION()
        function = ctypes.windll.advapi32.CreateProcessWithLogonW
        function.argtypes = [
            wintypes.LPCWSTR, wintypes.LPCWSTR, wintypes.LPCWSTR, wintypes.DWORD,
            wintypes.LPCWSTR, wintypes.LPWSTR, wintypes.DWORD, wintypes.LPVOID,
            wintypes.LPCWSTR, ctypes.POINTER(STARTUPINFOW), ctypes.POINTER(PROCESS_INFORMATION),
        ]
        function.restype = wintypes.BOOL
        ok = function(
            username, ".", password, LOGON_WITH_PROFILE, None, command_buffer,
            CREATE_UNICODE_ENVIRONMENT, environment_buffer, str(cwd),
            ctypes.byref(startup), ctypes.byref(process_info),
        )
        if not ok:
            raise ctypes.WinError(ctypes.get_last_error())
        ctypes.windll.kernel32.CloseHandle(process_info.hThread)
        return WindowsProcess(process_info.hProcess, None, process_info.dwProcessId)

    def remove_windows_identity(self, account):
        if os.name != "nt":
            return
        username = str(account.get("windows_user") or "")
        if not username.startswith("AkaQQ") or username != self.windows_username(account["uin"]):
            return
        try:
            try:
                win32net.NetLocalGroupDelMembers(
                    None, "Administrators", [{"domainandname": username}]
                )
            except Exception:
                pass
            win32net.NetUserDel(None, username)
        except Exception:
            pass

    def kill_windows_identity_processes(self, account):
        if os.name != "nt" or not account.get("profile_isolated"):
            return
        username = str(account.get("windows_user") or "")
        if not username.startswith("AkaQQ"):
            return
        script = (
            "$target='" + username.replace("'", "''") + "';"
            "Get-CimInstance Win32_Process | Where-Object {$_.Name -in @('QQ.exe','NapCatWinBootMain.exe','cmd.exe')} | "
            "ForEach-Object {$o=Invoke-CimMethod -InputObject $_ -MethodName GetOwner -ErrorAction SilentlyContinue;"
            "if($o.User -eq $target){Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue}}"
        )
        subprocess.run(
            ["powershell", "-NoLogo", "-NoProfile", "-NonInteractive", "-Command", script],
            capture_output=True, creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0), check=False,
        )

    def windows_identity_running(self, account):
        if os.name != "nt" or not account.get("profile_isolated"):
            return False
        username = str(account.get("windows_user") or "")
        if not username.startswith("AkaQQ"):
            return False
        script = (
            "$target='" + username.replace("'", "''") + "';"
            "$found=$false;Get-CimInstance Win32_Process | Where-Object {$_.Name -in @('QQ.exe','NapCatWinBootMain.exe')} | "
            "ForEach-Object {$o=Invoke-CimMethod -InputObject $_ -MethodName GetOwner -ErrorAction SilentlyContinue;"
            "if($o.User -eq $target){$found=$true}};if($found){exit 0}else{exit 1}"
        )
        result = subprocess.run(
            ["powershell", "-NoLogo", "-NoProfile", "-NonInteractive", "-Command", script],
            capture_output=True, creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0), check=False,
        )
        return result.returncode == 0

    def message_callback_url(self):
        return f"http://{self.message_store_bind}:{self.message_store_port_listen}{self.message_store_path}"

    def db_conn(self):
        if not self.message_store_enabled:
            raise RuntimeError("消息入库未启用")
        if self.message_store_db:
            try:
                self.message_store_db.ping(reconnect=True)
                return self.message_store_db
            except Exception:
                self.message_store_db = None
        self.message_store_db = pymysql.connect(
            host=self.message_store_host,
            port=self.message_store_port,
            user=self.message_store_user,
            password=self.message_store_pass,
            database=self.message_store_name,
            charset="utf8mb4",
            autocommit=True,
        )
        return self.message_store_db

    def new_db_conn(self):
        return pymysql.connect(
            host=self.message_store_host,
            port=self.message_store_port,
            user=self.message_store_user,
            password=self.message_store_pass,
            database=self.message_store_name,
            charset="utf8mb4",
            autocommit=True,
        )

    def ensure_message_tables(self):
        conn = self.db_conn()
        with conn.cursor() as cursor:
            cursor.execute(
                """
                CREATE TABLE IF NOT EXISTS private_messages (
                  id BIGINT AUTO_INCREMENT PRIMARY KEY,
                  account_uin VARCHAR(20) NOT NULL,
                  peer_uin VARCHAR(20) NOT NULL,
                  sender_uin VARCHAR(20) NOT NULL DEFAULT '',
                  receiver_uin VARCHAR(20) NOT NULL DEFAULT '',
                  direction VARCHAR(10) NOT NULL DEFAULT '',
                  msg_type VARCHAR(30) NOT NULL DEFAULT 'text',
                  content MEDIUMTEXT,
                  raw_data MEDIUMTEXT,
                  msg_time INT NOT NULL DEFAULT 0,
                  msg_seq BIGINT NOT NULL DEFAULT 0,
                  msg_id VARCHAR(64) NOT NULL DEFAULT '',
                  created_at INT NOT NULL,
                  INDEX idx_pm_account_peer_time (account_uin, peer_uin, msg_time),
                  INDEX idx_pm_account_time (account_uin, msg_time),
                  UNIQUE KEY uniq_pm_account_msg (account_uin, msg_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                """
            )
            cursor.execute(
                """
                CREATE TABLE IF NOT EXISTS group_messages (
                  id BIGINT AUTO_INCREMENT PRIMARY KEY,
                  account_uin VARCHAR(20) NOT NULL,
                  group_id VARCHAR(20) NOT NULL,
                  sender_uin VARCHAR(20) NOT NULL DEFAULT '',
                  sender_nickname VARCHAR(200) NOT NULL DEFAULT '',
                  sender_card VARCHAR(200) NOT NULL DEFAULT '',
                  msg_type VARCHAR(30) NOT NULL DEFAULT 'text',
                  content MEDIUMTEXT,
                  raw_data MEDIUMTEXT,
                  msg_time INT NOT NULL DEFAULT 0,
                  msg_seq BIGINT NOT NULL DEFAULT 0,
                  msg_id VARCHAR(64) NOT NULL DEFAULT '',
                  created_at INT NOT NULL,
                  INDEX idx_gm_account_group_time (account_uin, group_id, msg_time),
                  INDEX idx_gm_account_time (account_uin, msg_time),
                  INDEX idx_gm_group_sender (account_uin, group_id, sender_uin),
                  UNIQUE KEY uniq_gm_account_msg (account_uin, msg_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                """
            )
            cursor.execute(
                """
                CREATE TABLE IF NOT EXISTS collection_items (
                  cid VARCHAR(100) PRIMARY KEY,
                  account_uin VARCHAR(20) NOT NULL,
                  item_type INT NOT NULL DEFAULT 0,
                  author_num_id VARCHAR(20) NOT NULL DEFAULT '',
                  author_name VARCHAR(200) NOT NULL DEFAULT '',
                  author_group_id VARCHAR(20) NOT NULL DEFAULT '',
                  author_group_name VARCHAR(200) NOT NULL DEFAULT '',
                  category INT NOT NULL DEFAULT 0,
                  collect_time BIGINT NOT NULL DEFAULT 0,
                  create_time BIGINT NOT NULL DEFAULT 0,
                  modify_time BIGINT NOT NULL DEFAULT 0,
                  brief TEXT,
                  raw_data MEDIUMTEXT,
                  created_at INT NOT NULL,
                  updated_at INT NOT NULL,
                  KEY idx_collection_account_time (account_uin, collect_time)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                """
            )
            cursor.execute(
                """
                CREATE TABLE IF NOT EXISTS collection_media (
                  id BIGINT AUTO_INCREMENT PRIMARY KEY,
                  cid VARCHAR(100) NOT NULL,
                  account_uin VARCHAR(20) NOT NULL,
                  media_index INT NOT NULL DEFAULT 0,
                  source_uri TEXT,
                  pic_id VARCHAR(120) NOT NULL DEFAULT '',
                  md5 VARCHAR(64) NOT NULL DEFAULT '',
                  width INT NOT NULL DEFAULT 0,
                  height INT NOT NULL DEFAULT 0,
                  size_bytes BIGINT NOT NULL DEFAULT 0,
                  oss_key VARCHAR(500) NOT NULL DEFAULT '',
                  public_url TEXT,
                  created_at INT NOT NULL,
                  updated_at INT NOT NULL,
                  UNIQUE KEY uniq_collection_media (cid, media_index),
                  KEY idx_collection_media_account (account_uin, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                """
            )
            cursor.execute(
                """
                CREATE TABLE IF NOT EXISTS synced_friends (
                  account_uin VARCHAR(20) NOT NULL,
                  user_id VARCHAR(20) NOT NULL,
                  nickname VARCHAR(200) NOT NULL DEFAULT '',
                  remark VARCHAR(200) NOT NULL DEFAULT '',
                  category_id VARCHAR(100) NOT NULL DEFAULT '',
                  raw_data MEDIUMTEXT,
                  synced_at INT NOT NULL DEFAULT 0,
                  PRIMARY KEY(account_uin, user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                """
            )
            cursor.execute(
                """
                CREATE TABLE IF NOT EXISTS synced_groups (
                  account_uin VARCHAR(20) NOT NULL,
                  group_id VARCHAR(20) NOT NULL,
                  group_name VARCHAR(200) NOT NULL DEFAULT '',
                  member_count INT NOT NULL DEFAULT 0,
                  max_member_count INT NOT NULL DEFAULT 0,
                  raw_data MEDIUMTEXT,
                  synced_at INT NOT NULL DEFAULT 0,
                  PRIMARY KEY(account_uin, group_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                """
            )
            cursor.execute(
                """
                CREATE TABLE IF NOT EXISTS sync_tasks (
                  id BIGINT AUTO_INCREMENT PRIMARY KEY,
                  account_uin VARCHAR(20) NOT NULL,
                  sync_type VARCHAR(50) NOT NULL,
                  status VARCHAR(20) NOT NULL DEFAULT 'pending',
                  detail TEXT,
                  created_at INT NOT NULL,
                  updated_at INT NOT NULL,
                  KEY idx_sync_tasks_account (account_uin, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                """
            )
            cursor.execute(
                """
                CREATE TABLE IF NOT EXISTS private_sync_progress (
                  account_uin VARCHAR(20) NOT NULL,
                  peer_uin VARCHAR(20) NOT NULL,
                  oldest_msg_seq BIGINT NOT NULL DEFAULT 0,
                  fetched_count INT NOT NULL DEFAULT 0,
                  status VARCHAR(20) NOT NULL DEFAULT 'pulling',
                  updated_at INT NOT NULL,
                  PRIMARY KEY (account_uin, peer_uin)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                """
            )

    def build_onebot_config(self, uin, port):
        http_clients = []
        if self.message_store_enabled:
            http_clients.append({
                "enable": True,
                "name": f"message-store-{uin}",
                "url": self.message_callback_url(),
                "messagePostFormat": "array",
                "reportSelfMessage": True,
                "token": self.message_store_secret,
                "debug": False,
            })
        return {
            "network": {
                "httpServers": [{
                    "enable": True, "name": f"readonly-{uin}", "host": "127.0.0.1",
                    "port": port, "enableCors": False, "enableWebsocket": False,
                    "messagePostFormat": "array", "token": "", "debug": False,
                }],
                "httpSseServers": [], "httpClients": http_clients, "websocketServers": [],
                "websocketClients": [], "plugins": [],
            },
            "musicSignUrl": "", "enableLocalFile2Url": False, "parseMultMsg": False,
            "imageDownloadProxy": "",
            "timeout": {"baseTimeout": 10000, "uploadSpeedKBps": 256, "downloadSpeedKBps": 256, "maxTimeout": 1800000},
        }

    @staticmethod
    def normalize_message_content(message):
        if isinstance(message, str):
            return message
        if not isinstance(message, list):
            return ""
        parts = []
        for segment in message:
            if not isinstance(segment, dict):
                continue
            seg_type = str(segment.get("type") or "")
            data = segment.get("data") or {}
            if seg_type == "text":
                parts.append(str(data.get("text") or ""))
            elif seg_type == "image":
                parts.append("[image]")
            elif seg_type == "face":
                parts.append("[face]")
            elif seg_type == "reply":
                parts.append("[reply]")
            elif seg_type:
                parts.append("[" + seg_type + "]")
        return "".join(parts)[:20000]

    @staticmethod
    def collection_brief(item):
        summary = item.get("summary") or {}
        for key in ("richMediaSummary", "linkSummary", "textSummary", "gallerySummary", "audioSummary", "videoSummary", "fileSummary", "locationSummary"):
            value = summary.get(key)
            if isinstance(value, dict):
                brief = str(value.get("brief") or value.get("title") or value.get("subTitle") or "").strip()
                if brief:
                    return brief
            elif isinstance(value, str) and value.strip():
                return value.strip()
        return ""

    @staticmethod
    def collection_media(item):
        summary = item.get("summary") or {}
        media = []
        for key in ("richMediaSummary", "linkSummary", "gallerySummary"):
            block = summary.get(key) or {}
            pics = block.get("picList") or []
            if isinstance(pics, list):
                media.extend(pics)
        return media

    @staticmethod
    def _media_ext(uri, pic_id=""):
        ext = "jpg"
        try:
            path = urllib.parse.urlsplit(uri).path
            guess = os.path.splitext(path)[1].lstrip(".").lower()
        except Exception:
            guess = ""
        if guess in ("jpg", "jpeg", "png", "gif", "webp", "bmp", "heic", "ico", "mp4", "mov", "m4a"):
            ext = guess
        return ext

    def upload_collection_media_to_oss(self, account_uin, items, need_upload):
        if not self.oss_enabled or not need_upload:
            return
        uploaded = 0
        failed = 0
        try:
            import oss2
        except Exception as error:
            print(f"oss2 未安装，跳过 OSS 上传: {error}")
            return
        try:
            auth = oss2.Auth(self.oss_ak, self.oss_sk)
            bucket = oss2.Bucket(auth, self.oss_endpoint, self.oss_bucket)
        except Exception as error:
            print(f"OSS 初始化失败: {error}")
            return
        for item in items:
            cid = str(item.get("cid") or "")
            if not cid:
                continue
            for media_index, pic in enumerate(self.collection_media(item), start=1):
                if (cid, media_index) not in need_upload:
                    continue
                uri = str(pic.get("uri") or "")
                if not uri:
                    continue
                md5 = str(pic.get("md5") or "")[:8]
                ext = self._media_ext(uri, str(pic.get("picId") or ""))
                obj_key = f"{self.oss_prefix}/{account_uin}/images/{cid}_{media_index}_{md5 or 'x'}.{ext}"
                try:
                    req = urllib.request.Request(uri, headers={"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64)"})
                    with urllib.request.urlopen(req, timeout=self.oss_download_timeout) as resp:
                        data = resp.read()
                    if not data:
                        failed += 1
                        continue
                    bucket.put_object(obj_key, data)
                    public_url = self.oss_public_base + "/" + obj_key
                    try:
                        conn = self.db_conn()
                        with conn.cursor() as cur:
                            cur.execute("UPDATE collection_media SET oss_key=%s, public_url=%s WHERE cid=%s AND account_uin=%s AND media_index=%s", (obj_key, public_url, cid, account_uin, media_index))
                    except Exception as error:
                        print(f"OSS 回写数据库失败 {obj_key}: {error}")
                    uploaded += 1
                except Exception as error:
                    failed += 1
                    print(f"OSS 上传失败 {obj_key}: {error}")
        print(f"OSS 上传完成: {uploaded} 成功, {failed} 失败 (账号 {account_uin})")

    def mark_sync_task(self, account_uin, sync_type, status, detail=""):
        try:
            conn = self.db_conn()
            now_ts = int(time.time())
            with conn.cursor() as cursor:
                cursor.execute(
                    "INSERT INTO sync_tasks(account_uin,sync_type,status,detail,created_at,updated_at) VALUES(%s,%s,%s,%s,%s,%s)",
                    (str(account_uin), str(sync_type), str(status), str(detail)[:2000], now_ts, now_ts),
                )
        except Exception as error:
            print(f"mark_sync_task error ({account_uin}/{sync_type}): {error}")

    def sync_collection_to_db(self, account, count=50):
        account_uin = str(account["uin"])
        result = self.napcat(account, "/get_collection_list", 60, {"category": "0", "count": str(count)})
        data = result.get("data") or {}
        items = (data.get("collectionSearchList") or {}).get("collectionItemList") or []
        conn = self.db_conn()
        now_ts = int(time.time())
        with conn.cursor() as cursor:
            cursor.execute("SELECT cid FROM collection_items WHERE account_uin=%s", (account_uin,))
            existing_items = {str(row[0]) for row in cursor.fetchall()}
            cursor.execute("SELECT cid,media_index,oss_key FROM collection_media WHERE account_uin=%s", (account_uin,))
            media_rows = cursor.fetchall()
            existing_media = {(str(row[0]), int(row[1])) for row in media_rows}
            need_upload = {(str(row[0]), int(row[1])) for row in media_rows if not (row[2] or "")}
            for item in items:
                author = item.get("author") or {}
                cid = str(item.get("cid") or "")
                values = (
                    int(item.get("type") or 0), str(author.get("numId") or "")[:20], str(author.get("strId") or "")[:200],
                    str(author.get("groupId") or "")[:20], str(author.get("groupName") or "")[:200], int(item.get("category") or 0),
                    int(item.get("collectTime") or 0), int(item.get("createTime") or 0), int(item.get("modifyTime") or 0),
                    self.collection_brief(item), json.dumps(item, ensure_ascii=False), now_ts,
                )
                if cid in existing_items:
                    cursor.execute(
                        "UPDATE collection_items SET item_type=%s,author_num_id=%s,author_name=%s,author_group_id=%s,author_group_name=%s,category=%s,collect_time=%s,create_time=%s,modify_time=%s,brief=%s,raw_data=%s,updated_at=%s WHERE cid=%s AND account_uin=%s",
                        values + (cid, account_uin),
                    )
                else:
                    cursor.execute(
                        "INSERT INTO collection_items(cid,account_uin,item_type,author_num_id,author_name,author_group_id,author_group_name,category,collect_time,create_time,modify_time,brief,raw_data,created_at,updated_at) VALUES(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)",
                        (cid, account_uin) + values[:-1] + (now_ts, now_ts),
                    )
                    existing_items.add(cid)
                for media_index, pic in enumerate(self.collection_media(item), start=1):
                    media_values = (str(pic.get("uri") or ""), str(pic.get("picId") or "")[:120], str(pic.get("md5") or "")[:64], int(pic.get("width") or 0), int(pic.get("height") or 0), int(pic.get("size") or 0), now_ts)
                    key = (cid, media_index)
                    if key in existing_media:
                        cursor.execute("UPDATE collection_media SET source_uri=%s,pic_id=%s,md5=%s,width=%s,height=%s,size_bytes=%s,updated_at=%s WHERE cid=%s AND account_uin=%s AND media_index=%s", media_values + (cid, account_uin, media_index))
                    else:
                        cursor.execute("INSERT INTO collection_media(cid,account_uin,media_index,source_uri,pic_id,md5,width,height,size_bytes,created_at,updated_at) VALUES(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)", (cid, account_uin, media_index) + media_values[:-1] + (now_ts, now_ts))
                        existing_media.add(key)
                        need_upload.add(key)
        self.upload_collection_media_to_oss(account_uin, items, need_upload)
        self.mark_sync_task(account_uin, "collections", "done", f"{len(items)} items")

    def sync_friends_to_db(self, account):
        account_uin = str(account["uin"])
        friends = self.napcat(account, "/get_friend_list", 60).get("data") or []
        conn = self.db_conn()
        now_ts = int(time.time())
        with conn.cursor() as cursor:
            cursor.execute("DELETE FROM synced_friends WHERE account_uin=%s", (account_uin,))
            for item in friends:
                cursor.execute(
                    """
                    INSERT INTO synced_friends(account_uin,user_id,nickname,remark,category_id,raw_data,synced_at)
                    VALUES(%s,%s,%s,%s,%s,%s,%s)
                    """,
                    (
                        account_uin, str(item.get("user_id") or item.get("uin") or "")[:20], str(item.get("nickname") or "")[:200],
                        str(item.get("remark") or "")[:200], str(item.get("category_id") or "")[:100], json.dumps(item, ensure_ascii=False), now_ts,
                    ),
                )
        self.mark_sync_task(account_uin, "friends", "done", f"{len(friends)} friends")
        return friends

    def sync_groups_to_db(self, account):
        account_uin = str(account["uin"])
        groups = self.napcat(account, "/get_group_list", 120).get("data") or []
        conn = self.db_conn()
        now_ts = int(time.time())
        with conn.cursor() as cursor:
            cursor.execute("DELETE FROM synced_groups WHERE account_uin=%s", (account_uin,))
            for item in groups:
                cursor.execute(
                    """
                    INSERT INTO synced_groups(account_uin,group_id,group_name,member_count,max_member_count,raw_data,synced_at)
                    VALUES(%s,%s,%s,%s,%s,%s,%s)
                    """,
                    (
                        account_uin, str(item.get("group_id") or "")[:20], str(item.get("group_name") or "")[:200],
                        int(item.get("member_count") or 0), int(item.get("max_member_count") or 0), json.dumps(item, ensure_ascii=False), now_ts,
                    ),
                )
        self.mark_sync_task(account_uin, "groups", "done", f"{len(groups)} groups")
        return groups

    def sync_private_history(self, account, contacts, deadline=None):
        limit = self.private_history_limit
        if limit <= 0 or contacts is None:
            return {"status": "skipped", "pulled": 0, "friends_done": 0, "remaining": 0}
        account_uin = str(account["uin"])
        conn = self.db_conn()

        def save_progress(peer_uin, oldest_seq, status, added):
            try:
                with self.message_write_lock, conn.cursor() as cursor:
                    cursor.execute(
                        "INSERT INTO private_sync_progress(account_uin,peer_uin,oldest_msg_seq,fetched_count,status,updated_at) VALUES(%s,%s,%s,%s,%s,%s) "
                        "ON DUPLICATE KEY UPDATE oldest_msg_seq=VALUES(oldest_msg_seq),fetched_count=fetched_count+%s,status=VALUES(status),updated_at=VALUES(updated_at)",
                        (account_uin, peer_uin, oldest_seq, added, status, int(time.time()), added),
                    )
            except Exception as error:
                print(f"private_sync_progress save error: {error}")

        progress = {}
        with conn.cursor() as cursor:
            cursor.execute("SELECT peer_uin, oldest_msg_seq, status FROM private_sync_progress WHERE account_uin=%s", (account_uin,))
            for row in cursor.fetchall():
                progress[str(row[0])] = (int(row[1] or 0), str(row[2]))
        existing = set()
        with conn.cursor() as cursor:
            cursor.execute("SELECT msg_id FROM private_messages WHERE account_uin=%s", (account_uin,))
            existing.update(str(row[0]) for row in cursor.fetchall())

        total_pulled = 0
        friends_done = 0
        seen_errors = []
        friends_total = sum(1 for contact in contacts if str(contact.get("user_id") or contact.get("uin") or ""))
        for contact in contacts:
            if deadline is not None and time.time() >= deadline:
                break
            peer_uin = str(contact.get("user_id") or contact.get("uin") or "")
            if not peer_uin:
                continue
            cursor_seq, status = progress.get(peer_uin, (0, ""))
            if status == "done":
                friends_done += 1
                continue
            added = 0
            while deadline is None or time.time() < deadline:
                try:
                    payload = {"user_id": peer_uin, "message_seq": cursor_seq, "count": limit}
                    if cursor_seq > 0:
                        payload["revoke"] = True
                    result = self.napcat(account, "/get_friend_msg_history", 60, payload)
                    messages = (result.get("data") or {}).get("messages") or []
                except Exception as exc:
                    error_text = str(exc)
                    if error_text not in seen_errors and len(seen_errors) < 5:
                        seen_errors.append(error_text)
                    try:
                        result = self.napcat(account, "/get_friend_msg_history", 60, {"user_id": peer_uin, "message_seq": cursor_seq, "count": limit})
                        messages = (result.get("data") or {}).get("messages") or []
                    except Exception as exc2:
                        error_text = str(exc2)
                        if error_text not in seen_errors and len(seen_errors) < 5:
                            seen_errors.append(error_text)
                        break
                if not messages:
                    status = "done"
                    break
                batch_oldest = cursor_seq
                with self.message_write_lock:
                    with conn.cursor() as cursor:
                        for msg in messages:
                            sender = msg.get("sender") or {}
                            message = msg.get("message")
                            content = str(msg.get("raw_message") or self.normalize_message_content(message) or "")[:20000]
                            msg_time = int(msg.get("time") or time.time())
                            msg_seq = int(msg.get("message_seq") or 0)
                            msg_id = str(msg.get("message_id") or f"private:{account_uin}:{peer_uin}:{msg_time}:{msg_seq}")[:64]
                            sender_uin = str(msg.get("user_id") or sender.get("user_id") or peer_uin)
                            direction = "out" if sender_uin == account_uin else "in"
                            if msg_seq > 0 and (batch_oldest == 0 or msg_seq < batch_oldest):
                                batch_oldest = msg_seq
                            if msg_id in existing:
                                continue
                            cursor.execute(
                                "INSERT INTO private_messages(account_uin,peer_uin,sender_uin,receiver_uin,direction,msg_type,content,raw_data,msg_time,msg_seq,msg_id,created_at) VALUES(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)",
                                (account_uin, peer_uin, sender_uin, account_uin if direction == "in" else peer_uin, direction, str(msg.get("sub_type") or "text")[:30], content, json.dumps(msg, ensure_ascii=False), msg_time, msg_seq, msg_id, int(time.time())),
                            )
                            existing.add(msg_id)
                            added += 1
                if batch_oldest == cursor_seq:
                    status = "done"
                    break
                cursor_seq = batch_oldest
                save_progress(peer_uin, cursor_seq, "pulling", 0)
            save_progress(peer_uin, cursor_seq, status, added)
            progress[peer_uin] = (cursor_seq, status)
            total_pulled += added
            if status == "done":
                friends_done += 1
        remaining = friends_total - friends_done
        if remaining > 0 and deadline is not None and time.time() >= deadline:
            status = "stopped"
        elif remaining > 0:
            status = "partial"
        else:
            status = "done"
        self.mark_sync_task(account_uin, "private_history", status, f"pulled={total_pulled} friends_done={friends_done} remaining={remaining}")
        return {"status": status, "pulled": total_pulled, "friends_done": friends_done, "remaining": remaining, "errors": seen_errors}

    def sync_qzone_private_album(self, account):
        self.mark_sync_task(str(account["uin"]), "qzone_private_album", "pending", "公开 API 未确认可直接获取私密空间相册，暂未自动同步")

    def refresh_account_lease(self, account_uin, http_url=""):
        if not self.idle_timeout:
            return
        live = self.find_account(account_uin)
        if live is None:
            return
        if http_url and str(live.get("http_url", "")) != str(http_url):
            return
        timestamp = int(time.time())
        live["last_activity"] = timestamp
        live["active_until"] = timestamp + self.idle_timeout

    def run_login_sync(self, account):
        account_uin = str(account["uin"])
        http_url = str(account.get("http_url", ""))
        try:
            self.mark_sync_task(account_uin, "login_sync", "running", "开始登录后同步")
            self.refresh_account_lease(account_uin, http_url)
            self.sync_collection_to_db(account)
            self.refresh_account_lease(account_uin, http_url)
            self.sync_qzone_private_album(account)
            friends = self.sync_friends_to_db(account)
            self.refresh_account_lease(account_uin, http_url)
            self.sync_groups_to_db(account)
            self.refresh_account_lease(account_uin, http_url)
            deadline = None
            if self.idle_timeout:
                live = self.find_account(account_uin)
                active_until = int((live or account).get("active_until") or (time.time() + self.idle_timeout))
                deadline = active_until - self.sync_stop_before
            result = self.sync_private_history(account, friends, deadline=deadline)
            if result.get("remaining", 0) > 0:
                detail = f"首批预算内拉取{result['pulled']}条/好友{result['friends_done']}个，剩余{result['remaining']}个好友，转入后台续传"
                self.mark_sync_task(account_uin, "login_sync", "stopped", detail)
                self.continue_private_sync(account, friends)
                return
            self.mark_sync_task(account_uin, "login_sync", "done", "登录后同步完成(收藏/好友/群/私聊)")
        except Exception as error:
            print(f"login_sync error for {account_uin}: {error}")
            try:
                self.mark_sync_task(account_uin, "login_sync", "failed", str(error))
            except Exception:
                pass
        finally:
            self.login_sync_threads.pop(account_uin, None)

    def continue_private_sync(self, account, contacts):
        account_uin = str(account["uin"])
        batch_seconds = max(30, int(self.config.get("private_sync_batch_seconds", 180)))
        fail_streak = 0
        while self.message_store_enabled and self.login_sync_enabled:
            online = False
            try:
                data = self.napcat(account, "/get_login_info", 5).get("data") or {}
                online = str(data.get("user_id", "")) == account_uin
            except Exception:
                online = False
            if not online:
                fail_streak += 1
                if fail_streak >= 6:
                    try:
                        self.mark_sync_task(account_uin, "login_sync", "stopped", "账号离线，私聊历史中断，待下次登录续传")
                    except Exception:
                        pass
                    return
                time.sleep(15)
                continue
            fail_streak = 0
            try:
                result = self.sync_private_history(account, contacts, deadline=time.time() + batch_seconds)
            except Exception as error:
                print(f"continue_private_sync error for {account_uin}: {error}")
                time.sleep(20)
                continue
            if result.get("remaining", 0) <= 0:
                try:
                    self.mark_sync_task(account_uin, "login_sync", "done", "登录后同步完成(收藏/好友/群/私聊)")
                except Exception:
                    pass
                return
            if int(result.get("pulled", 0)) > 0:
                self.refresh_account_lease(account_uin, str(account.get("http_url", "")))
            try:
                detail = f"后台续传中：本批拉取{result['pulled']}条/好友{result['friends_done']}个，剩余{result['remaining']}个"
                if result.get("errors"):
                    detail += "；错误:" + " | ".join(str(item) for item in result["errors"])
                self.mark_sync_task(account_uin, "login_sync", "stopped", detail)
            except Exception:
                pass
            if int(result.get("pulled", 0)) == 0:
                time.sleep(30)

    def trigger_login_sync(self, account):
        if not self.message_store_enabled or not self.login_sync_enabled:
            return
        account_uin = str(account["uin"])
        thread = self.login_sync_threads.get(account_uin)
        if thread and thread.is_alive():
            return
        thread = threading.Thread(target=self.run_login_sync, args=(dict(account),), daemon=True)
        self.login_sync_threads[account_uin] = thread
        thread.start()

    def store_message_event(self, payload):
        if payload.get("post_type") != "message":
            return
        message_type = str(payload.get("message_type") or "")
        account_uin = str(payload.get("self_id") or "")
        if not account_uin:
            return
        self.refresh_account_lease(account_uin)
        message = payload.get("message")
        raw_data = json.dumps(payload, ensure_ascii=False)
        content = str(payload.get("raw_message") or self.normalize_message_content(message) or "")[:20000]
        msg_time = int(payload.get("time") or time.time())
        msg_seq = int(payload.get("message_seq") or 0)
        msg_id = str(payload.get("message_id") or f"{message_type}:{account_uin}:{msg_time}:{msg_seq}:{payload.get('user_id') or ''}:{payload.get('group_id') or ''}")[:64]
        sender = payload.get("sender") or {}
        with self.message_write_lock:
            conn = self.new_db_conn()
            try:
                with conn.cursor() as cursor:
                    if message_type == "private":
                        peer_uin = str(payload.get("user_id") or "")
                        sender_uin = str(payload.get("user_id") or "")
                        direction = "out" if sender_uin == account_uin else "in"
                        cursor.execute("SELECT COUNT(*) FROM private_messages WHERE account_uin=%s AND msg_id=%s", (account_uin, msg_id))
                        if cursor.fetchone()[0]:
                            return
                        cursor.execute(
                            """
                            INSERT INTO private_messages(account_uin,peer_uin,sender_uin,receiver_uin,direction,msg_type,content,raw_data,msg_time,msg_seq,msg_id,created_at)
                            VALUES(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
                            """,
                            (
                                account_uin,
                                peer_uin,
                                sender_uin,
                                account_uin if direction == "in" else peer_uin,
                                direction,
                                str(payload.get("sub_type") or "text")[:30],
                                content,
                                raw_data,
                                msg_time,
                                msg_seq,
                                msg_id,
                                int(time.time()),
                            ),
                        )
                    elif message_type == "group":
                        group_id = str(payload.get("group_id") or "")
                        if not group_id:
                            return
                        cursor.execute("SELECT COUNT(*) FROM group_messages WHERE account_uin=%s AND msg_id=%s", (account_uin, msg_id))
                        if cursor.fetchone()[0]:
                            return
                        cursor.execute(
                            """
                            INSERT INTO group_messages(account_uin,group_id,sender_uin,sender_nickname,sender_card,msg_type,content,raw_data,msg_time,msg_seq,msg_id,created_at)
                            VALUES(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
                            """,
                            (
                                account_uin,
                                group_id,
                                str(payload.get("user_id") or ""),
                                str(sender.get("nickname") or "")[:200],
                                str(sender.get("card") or "")[:200],
                                str(payload.get("sub_type") or "text")[:30],
                                content,
                                raw_data,
                                msg_time,
                                msg_seq,
                                msg_id,
                                int(time.time()),
                            ),
                        )
            finally:
                conn.close()

    def start_message_server(self):
        if self.message_store_server:
            return
        agent = self
        path = self.message_store_path
        secret = self.message_store_secret

        def consume_messages():
            while True:
                payload = self.message_queue.get()
                try:
                    self.store_message_event(payload)
                except Exception as error:
                    print(f"message store error: {error}")
                finally:
                    self.message_queue.task_done()

        self.message_worker_thread = threading.Thread(target=consume_messages, daemon=True)
        self.message_worker_thread.start()

        class MessageHandler(BaseHTTPRequestHandler):
            def do_POST(self):
                if self.path != path:
                    self.send_response(404)
                    self.end_headers()
                    return
                if secret and self.client_address[0] not in ("127.0.0.1", "::1"):
                    supplied = [
                        str(self.headers.get("Authorization") or ""),
                        str(self.headers.get("X-Access-Token") or ""),
                        str(self.headers.get("Access-Token") or ""),
                    ]
                    accepted = (secret, "Bearer " + secret)
                    if not any(hmac.compare_digest(value, expected) for value in supplied for expected in accepted):
                        self.send_response(401)
                        self.end_headers()
                        return
                length = int(self.headers.get("Content-Length") or 0)
                raw = self.rfile.read(length)
                try:
                    payload = json.loads(raw.decode("utf-8")) if raw else {}
                    agent.message_queue.put_nowait(payload)
                    self.send_response(204)
                    self.end_headers()
                except queue.Full:
                    self.send_response(503)
                    self.end_headers()
                except Exception as error:
                    self.send_response(500)
                    self.send_header("Content-Type", "application/json; charset=utf-8")
                    self.end_headers()
                    self.wfile.write(json.dumps({"ok": False, "error": str(error)}, ensure_ascii=False).encode("utf-8"))

            def log_message(self, format, *args):
                return

        self.message_store_server = ThreadingHTTPServer((self.message_store_bind, self.message_store_port_listen), MessageHandler)
        self.message_store_server.daemon_threads = True
        self.message_store_thread = threading.Thread(target=self.message_store_server.serve_forever, daemon=True)
        self.message_store_thread.start()

    def next_port(self, kind="http"):
        if kind == "webui":
            base = int(self.config.get("webui_port_start", 6100))
            account_key = "webui_url"
        else:
            base = int(self.config.get("http_port_start", 3001))
            account_key = "http_url"
        step = max(1, int(self.config.get("port_step", 10)))
        used = set()
        for account in self.accounts:
            if kind == "http":
                port = int(account.get("http_port", 0))
            else:
                parsed = urllib.parse.urlparse(str(account.get(account_key) or ""))
                port = int(parsed.port or 0)
            if port > 0:
                used.add(port)
        reserved = {self.message_store_port_listen}
        port = base
        while port in used or port in reserved or not self.port_available(port):
            port += step
        return port

    @staticmethod
    def port_available(port):
        try:
            with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as listener:
                listener.bind(("127.0.0.1", int(port)))
            return True
        except OSError:
            return False

    def create_account(self, uin):
        uin = str(uin).strip()
        if not uin.isdigit() or len(uin) < 5:
            raise RuntimeError("QQ号格式错误")
        existing = self.find_account(uin)
        if existing:
            self.activate_account(existing)
            return {"uin": uin, "http_port": existing["http_port"], "created": False}
        if self.slot_pool_enabled:
            return self.create_account_pool(uin)
        self.ensure_capacity()
        account = self._materialize_runtime(uin)
        self.accounts.append(account)
        self.save_config()
        runtime = Path(str(account["working_directory"]))
        self.ensure_ffmpeg(runtime)
        self.ensure_windows_identity(account, runtime)
        self.ensure_started(account)
        return {"uin": uin, "http_port": account["http_port"], "created": True}

    def _materialize_runtime(self, uin):
        if not self.config.get("napcat_template") or not self.config.get("qq_path") or not self.config.get("runtimes_dir"):
            raise RuntimeError("节点未配置 napcat_template、qq_path 或 runtimes_dir")
        template = Path(str(self.config["napcat_template"])).resolve()
        qq_path = Path(str(self.config["qq_path"])).resolve()
        runtimes = Path(str(self.config["runtimes_dir"])).resolve()
        required = ("NapCatWinBootMain.exe", "NapCatWinBootHook.dll", "napcat.mjs", "qqnt.json")
        missing = [name for name in required if not (template / name).exists()]
        if missing:
            raise RuntimeError("NapCat模板缺少：" + ", ".join(missing))
        if not qq_path.exists():
            raise RuntimeError("找不到QQ.exe")
        runtime = runtimes / uin
        runtime.mkdir(parents=True, exist_ok=True)
        qr_path = runtime / "cache" / "qrcode.png"
        if qr_path.exists():
            try:
                qr_path.unlink()
            except OSError:
                pass
        for item in template.iterdir():
            destination = runtime / item.name
            if item.is_file() and item.name.lower() != "loadnapcat.js":
                if not destination.exists():
                    shutil.copy2(item, destination)
        for name in SHARED_DIRS:
            source = template / name
            destination = runtime / name
            if not source.exists() or destination.exists():
                continue
            if os.name == "nt":
                result = subprocess.run(
                    ["cmd", "/c", "mklink", "/J", str(destination), str(source)],
                    capture_output=True,
                    creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0),
                    check=False,
                )
                if result.returncode:
                    raise RuntimeError(f"创建共享目录链接失败：{name}")
            else:
                destination.symlink_to(source, target_is_directory=True)
        for name in ("config", "cache", "logs", "plugins"):
            (runtime / name).mkdir(exist_ok=True)
        source_plugins = template / "plugins"
        if source_plugins.exists():
            shutil.copytree(source_plugins, runtime / "plugins", dirs_exist_ok=True)
        load_path = (runtime / "napcat.mjs").as_posix()
        (runtime / "loadNapCat.js").write_text(
            f'(async () => {{await import("file:///{load_path}")}})()\n', encoding="utf-8"
        )
        port = self.next_port()
        webui_port = self.next_port("webui")
        webui_token = "napcat_" + uin
        onebot = self.build_onebot_config(uin, port)
        for config_name in ("onebot11.json", f"onebot11_{uin}.json"):
            (runtime / "config" / config_name).write_text(
                json.dumps(onebot, ensure_ascii=False, indent=2), encoding="utf-8"
            )
        source_napcat = template / "config" / "napcat.json"
        if source_napcat.exists():
            shutil.copy2(source_napcat, runtime / "config" / "napcat.json")
        webui = {"host": "127.0.0.1", "port": webui_port, "token": webui_token, "autoLoginAccount": "", "disableWebUI": False}
        (runtime / "config" / "webui.json").write_text(
            json.dumps(webui, ensure_ascii=False, indent=2), encoding="utf-8"
        )
        account = {
            "uin": uin,
            "http_port": port,
            "http_url": f"http://127.0.0.1:{port}",
            "qrcode_path": str(runtime / "cache" / "qrcode.png"),
            "auto_start": True,
            "use_start_script": False,
            "managed": True,
            "runtime_status": "active",
            "slot_released": False,
            "last_activity": int(time.time()),
            "active_until": int(time.time()) + self.idle_timeout if self.idle_timeout else 0,
            "working_directory": str(runtime),
            "launch_command": [
                str(runtime / "NapCatWinBootMain.exe"), str(qq_path),
                str(runtime / "NapCatWinBootHook.dll"),
            ],
            "environment": {
                "NAPCAT_PATCH_PACKAGE": str(runtime / "qqnt.json"),
                "NAPCAT_LOAD_PATH": str(runtime / "loadNapCat.js"),
                "NAPCAT_INJECT_PATH": str(runtime / "NapCatWinBootHook.dll"),
                "NAPCAT_MAIN_PATH": str(runtime / "napcat.mjs"),
                "NAPCAT_WORKDIR": str(runtime),
            },
            "webui_url": f"http://127.0.0.1:{webui_port}",
            "webui_token": webui_token,
            "profile_isolated": os.name == "nt",
        }
        return account

    def _process_key(self, account):
        return str(account.get("slot_id") or account["uin"])

    def _slot(self, account):
        slot_id = account.get("slot_id")
        if slot_id:
            slot = self.find_account(slot_id)
            if slot:
                return slot
        return account

    def create_slot(self, index):
        slot_id = "slot" + str(index).zfill(2)
        existing = self.find_account(slot_id)
        if existing:
            return existing
        account = self._materialize_runtime(slot_id)
        account["pooled"] = True
        account["slot_id"] = slot_id
        account["current_uin"] = None
        runtime = Path(str(account["working_directory"]))
        self.ensure_ffmpeg(runtime)
        self.ensure_windows_identity(account, runtime)
        self.accounts.append(account)
        self.save_config()
        self.ensure_started(account)
        return account

    def ensure_pool(self):
        try:
            for index in range(1, self.slot_pool_count + 1):
                slot = self.create_slot(index)
                self.ensure_started(slot)
            self.rebind_existing_accounts()
        except Exception as error:
            print(f"slot pool init error: {error}")

    def ensure_pool_blocking(self):
        deadline = time.time() + 300
        while sum(1 for account in self.accounts if account.get("pooled")) < self.slot_pool_count:
            if time.time() >= deadline:
                raise RuntimeError("节点槽位初始化失败")
            time.sleep(5)

    def rebind_existing_accounts(self):
        for account in list(self.accounts):
            if account.get("pooled") or account.get("slot_id"):
                continue
            if not account.get("managed"):
                continue
            if account.get("was_online") or self.windows_identity_running(account):
                continue
            self.stop_account(account, release_slot=True)
            slot = self.find_free_slot()
            if slot:
                self.bind_uin_to_slot(str(account["uin"]), slot, existing=account)

    def find_free_slot(self):
        for slot in self.accounts:
            if not slot.get("pooled"):
                continue
            current_uin = slot.get("current_uin")
            if not current_uin:
                return slot
            bound = self.find_account(current_uin)
            if not bound or bound.get("runtime_status") in ("idle_logged_out", "idle_offline"):
                return slot
        return None

    def wait_for_free_slot(self):
        deadline = time.time() + self.slot_pool_wait
        while True:
            slot = self.find_free_slot()
            if slot:
                return slot
            if time.time() >= deadline:
                raise RuntimeError(
                    f"节点已满（{self.slot_pool_count}个容器均在使用中），请等待空闲后重试"
                )
            time.sleep(min(10, deadline - time.time()))

    def bind_uin_to_slot(self, uin, slot, existing=None):
        timestamp = int(time.time())
        target = existing if existing is not None else dict(slot)
        target["uin"] = uin
        target["slot_id"] = slot["slot_id"]
        target["http_port"] = slot["http_port"]
        target["http_url"] = slot["http_url"]
        target["webui_url"] = slot["webui_url"]
        target["webui_token"] = slot["webui_token"]
        target["qrcode_path"] = slot["qrcode_path"]
        target["working_directory"] = slot["working_directory"]
        target["launch_command"] = slot["launch_command"]
        target["environment"] = slot["environment"]
        target["windows_user"] = slot["windows_user"]
        target["windows_password_dpapi"] = slot["windows_password_dpapi"]
        target["profile_isolated"] = True
        target["auto_start"] = True
        target["use_start_script"] = False
        target["managed"] = True
        target["runtime_status"] = "active"
        target["slot_released"] = False
        target["last_activity"] = timestamp
        target["active_until"] = timestamp + self.idle_timeout if self.idle_timeout else 0
        target["process_pid"] = slot.get("process_pid", 0)
        target["was_online"] = False
        target["bound_at"] = timestamp
        target.pop("pooled", None)
        target.pop("current_uin", None)
        if existing is None:
            self.accounts.append(target)
        slot["current_uin"] = uin
        self.save_config()
        return target

    def unbind_slot(self, slot):
        current_uin = slot.get("current_uin")
        if current_uin:
            self.accounts = [
                account for account in self.accounts if str(account.get("uin")) != str(current_uin)
            ]
            self.config["accounts"] = self.accounts
            slot["current_uin"] = None
            self.save_config()

    def create_account_pool(self, uin):
        self.ensure_pool_blocking()
        slot = self.wait_for_free_slot()
        if slot.get("current_uin"):
            self.unbind_slot(slot)
        account = self.bind_uin_to_slot(uin, slot)
        return {"uin": uin, "http_port": account["http_port"], "created": True, "slot": slot["slot_id"]}

    def write_onebot_config(self, account):
        runtime = Path(str(account["working_directory"]))
        port = int(account["http_port"])
        onebot = self.build_onebot_config(account["uin"], port)
        for config_name in ("onebot11.json", f"onebot11_{account['uin']}.json"):
            (runtime / "config" / config_name).write_text(
                json.dumps(onebot, ensure_ascii=False, indent=2), encoding="utf-8"
            )

    def enable_webui(self, account):
        parsed = urllib.parse.urlparse(str(account.get("webui_url") or ""))
        webui_port = int(parsed.port or self.next_port("webui"))
        webui_token = str(account.get("webui_token") or ("napcat_" + str(account["uin"])))
        runtime = Path(str(account.get("working_directory") or ""))
        account["webui_url"] = f"http://127.0.0.1:{webui_port}"
        account["webui_token"] = webui_token
        if runtime.exists():
            webui_cfg = {
                "host": "127.0.0.1",
                "port": webui_port,
                "token": webui_token,
                "autoLoginAccount": "",
                "disableWebUI": False,
            }
            (runtime / "config" / "webui.json").write_text(
                json.dumps(webui_cfg, ensure_ascii=False, indent=2), encoding="utf-8"
            )

    def ensure_slot(self, account):
        if int(account.get("http_port", 0)) > 0:
            return
        port = self.next_port()
        account["http_port"] = port
        account["http_url"] = f"http://127.0.0.1:{port}"
        account["slot_released"] = False
        self.write_onebot_config(account)

    def activate_account(self, account):
        if int(account.get("http_port", 0)) <= 0:
            self.ensure_capacity(account)
        self.ensure_slot(account)
        account["last_activity"] = int(time.time())
        account["active_until"] = account["last_activity"] + self.idle_timeout if self.idle_timeout else 0
        account["runtime_status"] = "active"
        self.save_config()
        self.ensure_started(account)

    def ensure_http_adapter(self, account):
        http_url = str(account.get("http_url") or "")
        if not http_url:
            return False
        if self.port_accepts_http(http_url, 2):
            return True
        # Logged-in QQ sometimes comes back before the readonly HTTP adapter binds.
        # Force one clean restart in the logged-in state so OneBot can attach.
        if account.get("http_adapter_restarted"):
            return False
        account["http_adapter_restarted"] = True
        self.save_config()
        self.restart_account(account)
        time.sleep(8)
        return self.port_accepts_http(http_url, 15)

    def ensure_capacity(self, target=None):
        self.enforce_idle_leases()
        active = sum(
            1
            for account in self.accounts
            if not account.get("pooled")
            and account is not target
            and int(account.get("http_port", 0)) > 0
            and account.get("runtime_status") not in ("idle_offline", "idle_logged_out")
        )
        if active >= self.max_active_accounts:
            raise RuntimeError(
                f"节点运行槽已满（{active}/{self.max_active_accounts}），请等待空闲账号释放"
            )

    def stop_account(self, account, release_slot=True):
        uin = str(account["uin"])
        key = self._process_key(account)
        process = self.processes.get(key)
        pid = process.pid if process and process.poll() is None else int(account.get("process_pid", 0))
        if pid:
            if os.name == "nt":
                subprocess.run(
                    ["taskkill", "/PID", str(pid), "/T", "/F"],
                    capture_output=True,
                    creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0),
                    check=False,
                )
            elif process:
                process.terminate()
        self.processes.pop(key, None)
        self.kill_windows_identity_processes(account)
        account["process_pid"] = 0
        account["active_until"] = 0
        account["runtime_status"] = "idle_offline"
        account["was_online"] = False
        if release_slot:
            account["http_port"] = 0
            account["http_url"] = ""
            account["slot_released"] = True
        self.save_config()

    def kill_stale_account_processes(self, account):
        if account.get("profile_isolated"):
            return
        runtime = str(account.get("working_directory") or "")
        if not runtime or os.name != "nt":
            return
        marker = runtime.lower()
        current_pid = os.getpid()
        try:
            output = subprocess.check_output(
                [
                    "powershell",
                    "-NoLogo",
                    "-NoProfile",
                    "-Command",
                    (
                        "Get-CimInstance Win32_Process | "
                        "Where-Object { $_.Name -eq 'NapCatWinBootMain.exe' -and $_.CommandLine -like '*"
                        + runtime.replace("'", "''")
                        + "*' } | Select-Object -ExpandProperty ProcessId"
                    ),
                ],
                stderr=subprocess.DEVNULL,
            )
        except Exception:
            return
        for line in output.decode("utf-8", errors="ignore").splitlines():
            line = line.strip()
            if not line.isdigit():
                continue
            pid = int(line)
            if pid == int(account.get("process_pid", 0)) or pid == current_pid:
                continue
            subprocess.run(
                ["taskkill", "/PID", str(pid), "/T", "/F"],
                capture_output=True,
                creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0),
                check=False,
            )

    def enforce_idle_leases(self):
        if not self.idle_timeout:
            return
        timestamp = int(time.time())
        for account in self.accounts:
            if account.get("pooled"):
                continue
            if str(account.get("uin")) in self.busy_accounts:
                continue
            if not account.get("managed"):
                continue
            deadline = int(account.get("active_until", 0))
            if deadline and timestamp >= deadline:
                self.release_account(account)
                continue
            if self.slot_pool_enabled and self.unlogged_timeout:
                bound_at = int(account.get("bound_at", 0))
                if not account.get("login_at") and bound_at and timestamp - bound_at >= self.unlogged_timeout:
                    self.release_account(account)

    def release_account(self, account):
        uin = str(account["uin"])
        reason = "登录后超时无操作，账号已释放"
        if not account.get("was_online"):
            reason = "绑定后未及时扫码登录，账号已释放"
        entry = self.assigned_accounts.setdefault(uin, {})
        entry["released"] = True
        entry["released_at"] = int(time.time())
        entry["reason"] = reason
        self.save_config()
        if self.slot_pool_enabled:
            self.logout_account(account)
        else:
            self.stop_account(account, release_slot=True)

    def clear_qq_login(self, account):
        if os.name != "nt":
            return
        username = str(account.get("windows_user") or "")
        if not username.startswith("AkaQQ"):
            return
        profile = Path(os.environ.get("SystemDrive", "C:") + "\\") / "Users" / username
        for rel in (
            r"Documents\Tencent Files\nt_qq",
            r"AppData\Roaming\Tencent",
            r"AppData\Roaming\QQ",
        ):
            target = profile / rel
            if target.exists():
                shutil.rmtree(target, ignore_errors=True)

    def logout_account(self, account):
        slot = self._slot(account)
        self.stop_account(account, release_slot=False)
        self.clear_qq_login(slot)
        self.unbind_slot(slot)
        slot["runtime_status"] = "idle_offline"
        slot["last_activity"] = 0
        slot["active_until"] = 0
        slot["was_online"] = False
        slot["process_pid"] = 0
        self.save_config()
        self.ensure_started(slot)

    def ensure_ffmpeg(self, runtime_dir):
        runtime_dir = Path(str(runtime_dir))
        if (runtime_dir / "ffmpeg" / "ffmpeg.exe").exists():
            return True
        candidates = []
        template = Path(str(self.config.get("napcat_template") or ""))
        if (template / "ffmpeg" / "ffmpeg.exe").exists():
            candidates.append(template / "ffmpeg")
        for account in self.accounts:
            source = Path(str(account.get("working_directory") or ""))
            if source == runtime_dir or not (source / "ffmpeg" / "ffmpeg.exe").exists():
                continue
            candidates.append(source / "ffmpeg")
            break
        for source in candidates:
            try:
                shutil.copytree(source, runtime_dir / "ffmpeg", dirs_exist_ok=True)
                if (runtime_dir / "ffmpeg" / "ffmpeg.exe").exists():
                    return True
            except OSError:
                continue
        return False

    def ensure_started(self, account):
        self.kill_stale_account_processes(account)
        uin = str(account["uin"])
        key = self._process_key(account)
        process = self.processes.get(key)
        if process and process.poll() is None:
            return
        if self.windows_identity_running(account):
            return
        if account.get("profile_isolated"):
            account["process_pid"] = 0
            self.save_config()
        saved_pid = int(account.get("process_pid", 0))
        if saved_pid:
            try:
                os.kill(saved_pid, 0)
                return
            except OSError:
                account["process_pid"] = 0
        command = account.get("launch_command")
        runtime_dir = Path(str(account.get("working_directory") or Path(str(command[0])).resolve().parent if isinstance(command, list) and command else ""))
        start_script = runtime_dir / "Start-NapCat.bat"
        if account.get("use_start_script", True) and start_script.exists() and os.name == "nt":
            command = ["cmd", "/c", str(start_script), "-q", uin]
        if not account.get("auto_start", False) or not isinstance(command, list) or not command:
            return
        self.ensure_ffmpeg(runtime_dir)
        environment = os.environ.copy()
        environment.update(
            {str(key): str(value) for key, value in account.get("environment", {}).items()}
        )
        logs_dir = runtime_dir / "logs"
        logs_dir.mkdir(parents=True, exist_ok=True)
        launch_log = logs_dir / "napcat-launch.log"
        log_handle = open(launch_log, "ab")
        if os.name == "nt" and account.get("profile_isolated"):
            # The isolated cmd process performs its own redirection. Keeping this
            # handle open prevents the other Windows user from opening the log.
            log_handle.close()
            self.ensure_windows_identity(account, runtime_dir)
            username = str(account["windows_user"])
            password = self.unprotect_windows_password(account["windows_password_dpapi"])
            launcher = runtime_dir / "Launch-Isolated.cmd"
            lines = ["@echo off"]
            for key, value in account.get("environment", {}).items():
                lines.append(f'set "{key}={value}"')
            quoted = subprocess.list2cmdline([str(part) for part in command])
            lines.append(quoted + f' >> "{launch_log}" 2>&1')
            launcher.write_text("\r\n".join(lines) + "\r\n", encoding="utf-8")
            subprocess.run(
                ["icacls", str(launcher), "/grant", f"{username}:RX"], capture_output=True,
                creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0), check=False,
            )
            process = self.start_as_windows_user(
                username, password, ["cmd.exe", "/d", "/c", str(launcher)],
                runtime_dir, environment,
            )
        else:
            process = subprocess.Popen(
                [str(part) for part in command], cwd=str(runtime_dir), env=environment,
                stdout=log_handle, stderr=log_handle,
                creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0),
            )
        self.processes[key] = process
        account["log_path"] = str(launch_log)
        account["process_pid"] = process.pid
        self.save_config()

        http_url = str(account.get("http_url") or "")
        if http_url:
            self.wait_for_http_service(http_url, 20)

    def restart_account(self, account):
        uin = str(account["uin"])
        key = self._process_key(account)
        self.webui_credentials.pop(uin, None)
        process = self.processes.get(key)
        pid = process.pid if process and process.poll() is None else int(account.get("process_pid", 0))
        if pid:
            if os.name == "nt":
                subprocess.run(
                    ["taskkill", "/PID", str(pid), "/T", "/F"],
                    capture_output=True,
                    creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0),
                    check=False,
                )
            elif process:
                process.terminate()
        self.processes.pop(key, None)
        self.kill_windows_identity_processes(account)
        account["process_pid"] = 0
        qr_path = Path(str(account.get("qrcode_path", "")))
        if qr_path.is_file():
            qr_path.unlink()
        self.ensure_started(account)

    def delete_account(self, uin):
        uin = str(uin)
        if self.message_store_enabled:
            with self.new_db_conn() as connection:
                with connection.cursor() as cursor:
                    for table in (
                        "collection_media", "collection_items", "private_messages", "group_messages",
                        "synced_friends", "synced_groups", "sync_tasks",
                    ):
                        cursor.execute(f"DELETE FROM {table} WHERE account_uin=%s", (uin,))
        self.assigned_accounts.pop(uin, None)
        self.save_config()
        account = self.find_account(uin)
        if not account:
            return {"deleted": True, "uin": uin}
        if self.slot_pool_enabled and account.get("slot_id"):
            slot = self._slot(account)
            self.webui_credentials.pop(uin, None)
            self.stop_account(account, release_slot=False)
            self.clear_qq_login(slot)
            self.accounts = [item for item in self.accounts if str(item.get("uin")) != uin]
            slot["current_uin"] = None
            self.config["accounts"] = self.accounts
            self.save_config()
            self.ensure_started(slot)
            return {"deleted": True, "uin": uin}
        runtime = Path(str(account.get("working_directory") or "")).resolve()
        runtimes = Path(str(self.config.get("runtimes_dir") or "")).resolve()
        expected = (runtimes / str(uin)).resolve()
        isolated_runtime = runtime == expected and runtime.parent == runtimes
        self.webui_credentials.pop(str(uin), None)
        self.stop_account(account, release_slot=True)
        self.kill_stale_account_processes(account)
        if isolated_runtime and runtime.exists():
            shutil.rmtree(runtime)
        self.accounts = [item for item in self.accounts if str(item.get("uin")) != str(uin)]
        if account.get("profile_isolated"):
            self.remove_windows_identity(account)
        self.config["accounts"] = self.accounts
        self.save_config()
        return {"deleted": True, "uin": str(uin)}

    def clear_login_challenge(self, account):
        account.pop("pending_login", None)
        self.save_config()

    def napcat(self, account, path, timeout=40, payload=None):
        return request_json(str(account["http_url"]).rstrip("/") + path, payload=payload, timeout=timeout)

    def webui_request(self, account, path, payload=None, timeout=15):
        webui_url = str(account.get("webui_url", "")).rstrip("/")
        webui_token = str(account.get("webui_token", ""))
        if not webui_url or not webui_token:
            raise RuntimeError("账号未配置 webui_url/webui_token")
        uin = str(account["uin"])

        def ensure_credential():
            credential = self.webui_credentials.get(uin)
            if credential:
                return credential
            digest = hashlib.sha256((webui_token + ".napcat").encode("utf-8")).hexdigest()
            result = request_json(webui_url + "/api/auth/login", payload={"hash": digest}, timeout=timeout)
            data = result.get("data") or {}
            credential = str(data.get("Credential", ""))
            if not credential:
                raise RuntimeError(result.get("message") or "NapCat WebUI 认证失败")
            self.webui_credentials[uin] = credential
            return credential

        data = None if payload is None else json.dumps(payload).encode("utf-8")
        for attempt in range(2):
            credential = ensure_credential()
            headers = {"Authorization": "Bearer " + credential, "Accept": "application/json"}
            if data is not None:
                headers["Content-Type"] = "application/json"
            request = urllib.request.Request(
                webui_url + path, data=data, headers=headers, method="POST" if data is not None else "GET"
            )
            try:
                with urllib.request.urlopen(request, timeout=timeout) as response:
                    result = json.loads(response.read().decode("utf-8"))
            except urllib.error.HTTPError as error:
                if error.code == 401:
                    self.webui_credentials.pop(uin, None)
                    if attempt == 0:
                        continue
                raise
            if result.get("code", 0) != 0:
                message = str(result.get("message") or "")
                if message == "Unauthorized":
                    self.webui_credentials.pop(uin, None)
                    if attempt == 0:
                        continue
                raise RuntimeError(message or str(result))
            return result
        raise RuntimeError("NapCat WebUI 认证失败")

    def password_login(self, account, password_md5):
        payload = {"uin": str(account["uin"]), "passwordMd5": password_md5}
        try:
            result = self.webui_request(account, "/api/QQLogin/PasswordLogin", payload, 25)
        except TimeoutError:
            # NapCat may switch to the final confirmation QR without returning a body.
            time.sleep(3)
            status = self.login_state(account, force_refresh=False)
            if status.get("qr_image") or status.get("login_error") or status.get("login_status") != "offline":
                self.clear_login_challenge(account)
                return {"needConfirmQr": True}
            raise
        data = result.get("data") or {}
        if data.get("needCaptcha") and data.get("proofWaterUrl"):
            account["pending_login"] = {
                "type": "captcha",
                "url": str(data.get("proofWaterUrl", "")),
                "password_md5": password_md5,
            }
        elif data.get("needNewDevice") and data.get("jumpUrl"):
            account["pending_login"] = {
                "type": "new_device",
                "url": str(data.get("jumpUrl", "")),
                "sig": str(data.get("newDevicePullQrCodeSig", "")),
                "password_md5": password_md5,
            }
        else:
            self.clear_login_challenge(account)
        self.save_config()
        return data

    def captcha_login(self, account, ticket, randstr, sid=""):
        challenge = account.get("pending_login") or {}
        password_md5 = challenge.get("password_md5") or account.get("password_md5")
        if not password_md5:
            raise RuntimeError("账号未保存密码摘要，无法提交验证码")
        payload = {
            "uin": str(account["uin"]),
            "passwordMd5": str(password_md5),
            "ticket": str(ticket),
            "randstr": str(randstr),
            "sid": str(sid or ""),
        }
        result = self.webui_request(account, "/api/QQLogin/CaptchaLogin", payload, 30)
        data = result.get("data") or {}
        if data.get("needNewDevice") and data.get("jumpUrl"):
            account["pending_login"] = {
                "type": "new_device",
                "url": str(data.get("jumpUrl", "")),
                "sig": str(data.get("newDevicePullQrCodeSig", "")),
                "password_md5": str(password_md5),
            }
            self.save_config()
        else:
            self.clear_login_challenge(account)
        return data

    def new_device_login(self, account):
        challenge = account.get("pending_login") or {}
        password_md5 = challenge.get("password_md5") or account.get("password_md5")
        sig = challenge.get("sig")
        if not password_md5 or not sig:
            raise RuntimeError("缺少新设备验证所需参数")
        payload = {
            "uin": str(account["uin"]),
            "passwordMd5": str(password_md5),
            "newDevicePullQrCodeSig": str(sig),
        }
        result = self.webui_request(account, "/api/QQLogin/NewDeviceLogin", payload, 30)
        data = result.get("data") or {}
        if data.get("needNewDevice") and data.get("jumpUrl"):
            account["pending_login"] = {
                "type": "new_device",
                "url": str(data.get("jumpUrl", "")),
                "sig": str(data.get("newDevicePullQrCodeSig", sig)),
                "password_md5": str(password_md5),
            }
            self.save_config()
        else:
            self.clear_login_challenge(account)
        return data

    def login_state(self, account, force_refresh=False):
        qr_path = Path(str(account.get("qrcode_path", "")))
        if not account.get("webui_url") or not account.get("webui_token"):
            if force_refresh:
                if qr_path.is_file():
                    try:
                        qr_path.unlink()
                    except OSError:
                        pass
                if not account.get("auto_start") or not account.get("launch_command"):
                    raise RuntimeError("未配置本机登录 API，且 Agent 不能重启该账号生成新二维码")
                self.restart_account(account)
            for _ in range(80 if force_refresh else 1):
                if qr_path.is_file() and (not force_refresh or time.time() - qr_path.stat().st_mtime < 20):
                    break
                time.sleep(0.25)
            if not qr_path.is_file():
                return {
                    "login_status": "waiting_qrcode",
                    "login_error": "等待 QQ/NapCat 生成二维码文件",
                    "qr_image": "",
                    "qr_updated_at": 0,
                }
            image = base64.b64encode(qr_path.read_bytes()).decode("ascii")
            stale = time.time() - qr_path.stat().st_mtime > 90
            return {
                "login_status": "waiting_scan",
                "login_error": "二维码可能已过期，请点击刷新" if stale else "",
                "qr_image": "data:image/png;base64," + image,
                "qr_updated_at": int(qr_path.stat().st_mtime),
            }
        result = self.webui_request(account, "/api/QQLogin/CheckLoginStatus", {}, 10)
        data = result.get("data") or {}
        if data.get("isLogin"):
            self.clear_login_challenge(account)
            return {"login_status": "online", "login_error": "", "qr_image": "", "qr_updated_at": 0}
        error = str(data.get("loginError", ""))
        if self.error_means_logged_in(error):
            self.clear_login_challenge(account)
            return {
                "login_status": "logged_in_pending_adapter",
                "login_error": "QQ 已登录，正在启动采集接口，请稍候重试。",
                "qr_image": "",
                "qr_updated_at": 0,
            }
        qrcode_url = str(data.get("qrcodeurl", ""))
        challenge = account.get("pending_login") or {}
        challenge_type = str(challenge.get("type", ""))
        challenge_url = str(challenge.get("url", ""))
        if challenge_type == "captcha" and challenge_url:
            error = "slider_url:" + challenge_url
        elif challenge_type == "new_device" and challenge_url:
            error = "device_url:" + challenge_url
        if challenge_type and challenge_url:
            force_refresh = False
        expired = "过期" in error
        stale = not qr_path.is_file() or time.time() - qr_path.stat().st_mtime > 90
        # Only refresh QR on explicit user action or an API-reported expiry.
        # Background status checks should not silently replace the confirmation QR.
        if force_refresh or (expired and not challenge_type):
            self.webui_request(account, "/api/QQLogin/RefreshQRcode", {}, 15)
            for _ in range(20):
                if qr_path.is_file() and time.time() - qr_path.stat().st_mtime < 20:
                    break
                time.sleep(0.25)
            result = self.webui_request(account, "/api/QQLogin/CheckLoginStatus", {}, 10)
            data = result.get("data") or {}
            error = str(data.get("loginError", ""))
            qrcode_url = str(data.get("qrcodeurl", ""))
        qr_image = ""
        if qr_path.is_file():
            qr_image = "data:image/png;base64," + base64.b64encode(qr_path.read_bytes()).decode("ascii")
        elif qrcode_url:
            qr_image = self.qr_image_from_url(qrcode_url)
        if challenge_type and challenge_url and not qr_path.is_file():
            return {"login_status": "waiting_verify", "login_error": error, "qr_image": "", "qr_updated_at": 0}
        if qr_image:
            return {
                "login_status": "waiting_scan",
                "login_error": error or "请使用手机 QQ 扫描二维码并确认登录。",
                "qr_image": qr_image,
                "qr_updated_at": int(time.time()),
            }
        if not qr_path.is_file():
            return {"login_status": "waiting_qrcode", "login_error": error or "二维码文件尚未生成", "qr_image": "", "qr_updated_at": 0}
        image = base64.b64encode(qr_path.read_bytes()).decode("ascii")
        return {
            "login_status": "waiting_scan",
            "login_error": error or "请使用手机 QQ 扫描二维码并确认登录。",
            "qr_image": "data:image/png;base64," + image,
            "qr_updated_at": int(qr_path.stat().st_mtime),
        }

    def heartbeat(self):
        self.enforce_idle_leases()
        accounts = []
        for account in self.accounts:
            if account.get("pooled"):
                continue
            configured_uin = str(account["uin"])
            if account.get("runtime_status") == "idle_offline" or not account.get("http_url"):
                try:
                    state = self.login_state(account)
                    if state.get("login_status") in ("online", "logged_in_pending_adapter"):
                        self.activate_account(account)
                    else:
                        accounts.append({
                            "uin": configured_uin, "nickname": "", "online": False,
                            "login_status": "idle_offline", "login_error": "连续10分钟无操作，实例已释放",
                            "qr_image": "", "qr_updated_at": 0,
                        })
                        continue
                except Exception:
                    accounts.append({
                        "uin": configured_uin, "nickname": "", "online": False,
                        "login_status": "idle_offline", "login_error": "连续10分钟无操作，实例已释放",
                        "qr_image": "", "qr_updated_at": 0,
                    })
                    continue
            self.ensure_started(account)
            try:
                result = self.napcat(account, "/get_login_info", 4)
                data = result.get("data") or {}
                actual_uin = str(data.get("user_id", ""))
                online = actual_uin == configured_uin
                if online and not account.get("was_online"):
                    account["last_activity"] = int(time.time())
                    account["active_until"] = account["last_activity"] + self.idle_timeout if self.idle_timeout else 0
                    account["was_online"] = True
                    account.setdefault("login_at", int(time.time()))
                    self.save_config()
                    self.trigger_login_sync(account)
                if online:
                    self.ensure_http_adapter(account)
                accounts.append(
                    {
                        "uin": configured_uin,
                        "nickname": str(data.get("nickname", "")),
                        "online": online,
                        "http_url": str(account["http_url"]),
                        "login_status": "online",
                        "login_error": "",
                    }
                )
            except Exception:
                try:
                    state = self.login_state(account)
                except Exception as error:
                    state = {
                        "login_status": "offline",
                        "login_error": str(error),
                        "qr_image": "",
                        "qr_updated_at": 0,
                    }
                if state.get("login_status") == "online":
                    self.ensure_http_adapter(account)
                    if not account.get("was_online"):
                        account["was_online"] = True
                        account["last_activity"] = int(time.time())
                        account["active_until"] = account["last_activity"] + self.idle_timeout if self.idle_timeout else 0
                        account.setdefault("login_at", int(time.time()))
                        self.save_config()
                        self.trigger_login_sync(account)
                elif state.get("login_status") == "logged_in_pending_adapter":
                    if self.ensure_http_adapter(account):
                        state = {"login_status": "online", "login_error": "", "qr_image": "", "qr_updated_at": 0}
                        if not account.get("was_online"):
                            account["was_online"] = True
                            account["last_activity"] = int(time.time())
                            account["active_until"] = account["last_activity"] + self.idle_timeout if self.idle_timeout else 0
                            account.setdefault("login_at", int(time.time()))
                            self.save_config()
                            self.trigger_login_sync(account)
                accounts.append({
                    "uin": configured_uin,
                    "nickname": "",
                    "online": state.get("login_status") == "online",
                    "http_url": str(account["http_url"]),
                    **state,
                })
        for uin, entry in list(self.assigned_accounts.items()):
            if self.find_account(uin):
                if entry.get("released"):
                    entry["released"] = False
                    self.save_config()
                continue
            if entry.get("released"):
                accounts.append({
                    "uin": uin, "nickname": "", "online": False,
                    "login_status": "idle_offline", "login_error": str(entry.get("reason") or "账号已释放"),
                    "qr_image": "", "qr_updated_at": 0,
                })
        request_json(
            self.cloud_url + "/api/agent/heartbeat",
            self.token,
            {"node_id": self.node_id, "name": self.name, "accounts": accounts},
        )

    def find_account(self, uin):
        return next((account for account in self.accounts if str(account["uin"]) == str(uin)), None)

    def execute(self, job):
        if job["action"] == "create_account":
            uin = job["payload"].get("uin") or job["account_uin"]
            result = self.create_account(uin)
            account = self.find_account(uin)
            if not account:
                raise RuntimeError("账号创建后未找到本地配置")
            if not self.wait_for_http_service(account.get("webui_url", ""), 90):
                raise RuntimeError("账号容器启动后 WebUI 未能及时启动")
            return {**result, **self.login_state(account, force_refresh=True)}
        if job["action"] in ("delete_account", "force_delete_account"):
            return self.delete_account(job["account_uin"])
        account = self.find_account(job["account_uin"])
        if not account:
            if self.slot_pool_enabled and job["action"] in ("restart_container", "start_account", "refresh_qrcode"):
                account = self.create_account_pool(job["account_uin"])
            else:
                raise RuntimeError("账号未配置在此节点")
        if job["action"] == "start_account":
            self.activate_account(account)
            if not self.wait_for_http_service(account.get("webui_url", ""), 90):
                raise RuntimeError("NapCat WebUI 未能及时启动")
            return self.login_state(account)
        if job["action"] == "restart_container":
            self.ensure_slot(account)
            timestamp = int(time.time())
            account["bound_at"] = timestamp
            account["last_activity"] = timestamp
            account["active_until"] = timestamp + self.idle_timeout if self.idle_timeout else 0
            account["runtime_status"] = "active"
            account["was_online"] = False
            self.save_config()
            self.restart_account(account)
            if not self.wait_for_http_service(account.get("webui_url", ""), 90):
                raise RuntimeError("NapCat 容器重启后 WebUI 未能及时启动")
            return self.login_state(account)
        if job["action"] == "refresh_qrcode":
            if account.get("runtime_status") == "idle_offline" or not account.get("http_url"):
                self.activate_account(account)
                if not self.wait_for_http_service(account.get("webui_url", ""), 90):
                    raise RuntimeError("账号容器启动后 WebUI 未能及时启动")
            return self.login_state(account, force_refresh=True)
        self.activate_account(account)
        if job["action"] == "submit_ticket":
            ticket = str(job["payload"].get("ticket") or "")
            randstr = str(job["payload"].get("randstr") or "")
            sid = str(job["payload"].get("sid") or "")
            if not ticket or not randstr:
                raise RuntimeError("缺少验证码票据或 randstr")
            self.captcha_login(account, ticket, randstr, sid)
            time.sleep(2)
            return self.login_state(account)
        if job["action"] == "submit_new_device":
            self.new_device_login(account)
            time.sleep(2)
            return self.login_state(account)
        login = self.napcat(account, "/get_login_info", 5).get("data") or {}
        if str(login.get("user_id", "")) != str(job["account_uin"]):
            raise RuntimeError("NapCat 离线或登录账号不匹配")
        account["http_adapter_restarted"] = False
        if job["action"] == "refresh_groups":
            return self.napcat(account, "/get_group_list", 600).get("data") or []
        if job["action"] == "refresh_friends":
            return self.napcat(account, "/get_friend_list", 60).get("data") or []
        if job["action"] in ("refresh_members", "refresh_members_export"):
            group_id = str(job["payload"]["group_id"])
            if not group_id.isdigit():
                raise RuntimeError("群号格式错误")
            query = urllib.parse.urlencode({"group_id": group_id, "no_cache": "true"})
            return self.napcat(account, "/get_group_member_list?" + query, 120).get("data") or []
        raise RuntimeError("未知任务类型")

    def complete(self, job_id, payload):
        request_json(self.cloud_url + f"/api/agent/jobs/{job_id}/complete", self.token, payload, 60)

    def heartbeat_loop(self):
        while True:
            try:
                self.heartbeat()
            except Exception as error:
                print(f"heartbeat error: {error}")
            time.sleep(15)

    def run(self):
        threading.Thread(target=self.heartbeat_loop, daemon=True).start()
        while True:
            try:
                query = urllib.parse.urlencode({"node_id": self.node_id})
                job = request_json(self.cloud_url + "/api/agent/jobs?" + query, self.token, timeout=20).get("job")
                if not job:
                    time.sleep(2)
                    continue
                account_uin = str(job.get("account_uin") or "")
                self.busy_accounts.add(account_uin)
                if account_uin:
                    entry = self.assigned_accounts.setdefault(account_uin, {})
                    entry["last_seen"] = int(time.time())
                    entry["released"] = False
                    self.save_config()
                try:
                    data = self.execute(job)
                    self.complete(job["id"], {"ok": True, "data": data})
                    count = len(data) if isinstance(data, list) else 1
                    print(f"completed {job['action']} for {job['account_uin']}: {count} records")
                except Exception as error:
                    self.complete(job["id"], {"ok": False, "error": str(error)})
                    print(f"failed job {job['id']} ({job.get('action')} for {job.get('account_uin')}): {error}")
                finally:
                    self.busy_accounts.discard(account_uin)
            except KeyboardInterrupt:
                return
            except Exception as error:
                print(f"agent error: {error}; retrying in 5 seconds")
                time.sleep(5)


def main():
    parser = argparse.ArgumentParser(description="SMTP QQ group collector agent")
    parser.add_argument("--config", default="agent_config.json")
    args = parser.parse_args()
    config = json.loads(Path(args.config).read_text(encoding="utf-8"))
    Agent(config, args.config).run()


if __name__ == "__main__":
    main()
