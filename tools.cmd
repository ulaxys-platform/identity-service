@echo off
rem Windows shortcut: .\tools test [suite]
rem All logic stays in the shared Python runner via tools.ps1.
powershell.exe -NoProfile -File "%~dp0tools.ps1" %*
exit /b %ERRORLEVEL%
