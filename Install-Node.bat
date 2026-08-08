@echo off
PowerShell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0Install-Node.ps1"
if errorlevel 1 pause
