# QQ 导出节点（纯净初始化版）

> 当前公开版本 **不包含 Program/agent.py**。该文件将等待你提供最新版并完成链接/密钥脱敏后再补充。
> 另外，本次公开内容中不包含 geetest / captcha 相关敏感流程文件。

这是一个用于部署 **QQ 导出节点 / NapCat 节点** 的纯净初始化版本。

本版本已经做了以下处理：

- 去除了顶层模板配置中的服务器域名 / IP
- 去除了模板中的 agent token / 本地 secret
- 去除了数据库主机 / 用户 / 密码 / 库名
- 保留 `Program/agent.py` 原逻辑文件（你要求暂时不动，后续由你自己再检查和处理）
- 不包含大体积安装包 / Node 依赖 / 运行时目录，便于直接放 GitHub 开源

## 当前保留的目录结构

```text
QQ节点-clean/
├── Program/
│   ├── agent.py
│   ├── export_only.py
│   ├── agent_config.example.json
│   └── requirements.txt
├── NapCat-Template-lite/
│   ├── launcher*.bat
│   ├── Start-NapCat.bat
│   ├── qqnt.json
│   ├── package.json
│   └── loadNapCat.js
├── php-panel/
│   ├── config.php
│   ├── api_agent.php
│   ├── login.php
│   ├── index.php
│   ├── includes/
│   ├── pages/
│   └── akalullusmtpy/
├── Check-Node.ps1
├── Install-Node.bat
├── Install-Node.ps1
├── Lock-QQ-Updates.ps1
├── Restart-Node.ps1
├── Unlock-QQ-Updates.ps1
├── VERSION.txt
└── README.md
```

## 已清理的敏感信息

以下信息已替换为占位符：

- `cloud_url` -> `http://your-server.example:28741`
- `agent_token` -> `REPLACE_WITH_AGENT_TOKEN`
- `message_store.db_host` -> `127.0.0.1`
- `message_store.db_user` -> `REPLACE_WITH_DB_USER`
- `message_store.db_pass` -> `REPLACE_WITH_DB_PASSWORD`
- `message_store.db_name` -> `REPLACE_WITH_DB_NAME`
- `message_store.secret` -> `REPLACE_WITH_LOCAL_SECRET`
- 示例 QQ 号 `123456` -> `10000`

在 `php-panel/config.php` 中也已替换为占位符：

- `DB_USER` -> `REPLACE_WITH_DB_USER`
- `DB_PASS` -> `REPLACE_WITH_DB_PASS`
- `DB_NAME` -> `REPLACE_WITH_DB_NAME`
- `GT4_CAPTCHA_ID` -> `REPLACE_WITH_GT4_CAPTCHA_ID`
- `GT4_CAPTCHA_KEY` -> `REPLACE_WITH_GT4_CAPTCHA_KEY`

## 需要你自己后续处理的部分

你之前说明：

> `agen 我一会儿给你去掉链接密钥`

因此：

- `Program/agent.py` **目前保留最新版逻辑**，我没有替你改动业务逻辑
- 我已经确认它不再直接包含你之前那批明显的域名 / 数据库 / token 真值，但仍建议你在公开前自己再检查一遍 `agent.py` 里是否还含有：
  - 私有接口地址
  - 认证 token / header
  - 数据库 / 本地机器信息
  - 任何你不希望公开的业务逻辑

## 如何使用（初始化版）

### 1. 复制模板配置

将：

```text
Program/agent_config.example.json
```

复制为：

```text
Program/agent_config.json
```

然后按你的实际环境填写：

- `cloud_url`
- `agent_token`
- `node_id`
- `name`
- `message_store.*`

### 2. 准备 NapCat 模板

当前仓库只保留了 `NapCat-Template-lite` 的**关键启动文件**，不带完整运行时依赖。

开源时推荐做法：

- 在 README 里说明使用者自行下载官方 NapCat 发行版
- 将本目录里的启动脚本 / 配置文件覆盖到自己的 NapCat 模板目录

### 3. 准备 Python 环境

安装依赖：

```bash
pip install -r Program/requirements.txt
```

### 4. 运行安装脚本

按需运行：

- `Install-Node.bat`
- `Install-Node.ps1`
- `Check-Node.ps1`
- `Restart-Node.ps1`

### 5. 初始化 PHP 面板

`php-panel/` 目录为配套的 PHP 面板与 API 服务（已做脱敏处理）。

首次使用前需要修改 `php-panel/config.php`：

- 数据库连接信息
- `SMTP_ADMIN_TOKEN` / `.private/node_token` 对应的 Node API token
- Geetest 配置（若你不使用验证码，可留空或按你的版本自行处理）

推荐做法：

1. 复制 `php-panel/` 到你的站点目录
2. 配置数据库并导入表结构（首次访问也会自动建部分表）
3. 配置 Node API token
4. 再访问后台 `akalullusmtpy/`

## 开源建议

在你真正推 GitHub 前，建议再做一轮自查：

1. 搜索 `http://` / `https://` / `ws://` / `wss://`
2. 搜索 `token` / `secret` / `password` / `Authorization`
3. 搜索你的域名、服务器 IP、数据库地址
4. 搜索真实 QQ 号 / 节点名 / 账号目录

## 说明

这是一个**纯净初始化版**，目的是：

- 保留项目结构
- 去掉敏感配置
- 便于公开分享 / 二次初始化

如果后续你要，我可以继续帮你：

1. 扫 `Program/agent.py` 的敏感内容并进一步清理
2. 初始化 Git 仓库
3. 推送到 GitHub
