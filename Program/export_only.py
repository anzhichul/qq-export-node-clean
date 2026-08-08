import argparse
import json
import os
import sys
from datetime import datetime
from pathlib import Path

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import agent as _agent

EXPORT_ACTIONS = ("refresh_friends", "refresh_groups", "refresh_members", "refresh_members_export")


class ExportAgent(_agent.Agent):
    def __init__(self, config, config_path=None):
        config = dict(config)
        message_store = dict(config.get("message_store") or {})
        message_store["enabled"] = False
        message_store["sync_on_login"] = False
        config["message_store"] = message_store
        oss = dict(config.get("oss") or {})
        oss["enabled"] = False
        config["oss"] = oss
        super().__init__(config, config_path)
        base_dir = self.config_path.parent if self.config_path else Path.cwd()
        self.export_dir = Path(str(config.get("export_dir") or base_dir / "exports")).resolve()
        self.export_dir.mkdir(parents=True, exist_ok=True)

    def trigger_login_sync(self, account):
        pass

    def store_message_event(self, payload):
        pass

    def start_message_server(self):
        pass

    def save_export(self, account_uin, action, data):
        target_dir = self.export_dir / str(account_uin) / action
        target_dir.mkdir(parents=True, exist_ok=True)
        path = target_dir / f"{datetime.now().strftime('%Y%m%d')}.json"
        path.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")
        return path

    def execute(self, job):
        result = super().execute(job)
        action = str(job.get("action") or "")
        if action in EXPORT_ACTIONS:
            try:
                path = self.save_export(str(job.get("account_uin") or ""), action, result)
                print(f"exported {action} -> {path}")
            except Exception as error:
                print(f"export save failed for {action}: {error}")
        return result


def main():
    parser = argparse.ArgumentParser(description="纯导出节点：按平台指令导出数据到本地文件，不入库、不传 OSS")
    parser.add_argument("--config", default="export_config.json")
    args = parser.parse_args()
    config_path = args.config
    if not os.path.exists(config_path):
        config_path = "agent_config.json"
    config = json.loads(Path(config_path).read_text(encoding="utf-8"))
    ExportAgent(config, config_path).run()


if __name__ == "__main__":
    main()
