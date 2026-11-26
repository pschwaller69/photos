@echo off
REM Double-cliquez pour lancer l indexation des photos.
cd /d "%~dp0"
python photoindex.py %*
echo.
pause
