@echo off
cd /d "%~dp0.."
node revenue-engine/launch.js
if errorlevel 1 pause
