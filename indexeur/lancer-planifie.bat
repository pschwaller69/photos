@echo off
REM Version pour le Planificateur de taches Windows (sans pause a la fin).
cd /d "%~dp0"
python photoindex.py %* >> planifie.log 2>&1
