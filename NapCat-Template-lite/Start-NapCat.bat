@echo off
chcp 65001 >nul
cd /d "%~dp0"

set "QQ_PATH=C:\Program Files\Tencent\QQNT\QQ.exe"
if not exist "%QQ_PATH%" (
    echo QQ not found: %QQ_PATH%
    pause
    exit /b 1
)

net session >nul 2>&1
if not "%ERRORLEVEL%"=="0" (
    powershell.exe -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
    exit /b
)

set "NAPCAT_PATCH_PACKAGE=%~dp0qqnt.json"
set "NAPCAT_LOAD_PATH=%~dp0loadNapCat.js"
set "NAPCAT_INJECT_PATH=%~dp0NapCatWinBootHook.dll"
set "NAPCAT_LAUNCHER_PATH=%~dp0NapCatWinBootMain.exe"
set "NAPCAT_MAIN_PATH=%~dp0napcat.mjs"
set "NAPCAT_MAIN_PATH=%NAPCAT_MAIN_PATH:\=/%"

>"%NAPCAT_LOAD_PATH%" echo (async () =^> {await import("file:///%NAPCAT_MAIN_PATH%")})()
"%NAPCAT_LAUNCHER_PATH%" "%QQ_PATH%" "%NAPCAT_INJECT_PATH%" %*

if errorlevel 1 (
    echo NapCat failed to start. Exit code: %ERRORLEVEL%
    pause
)
