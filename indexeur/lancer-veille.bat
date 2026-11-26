@echo off
REM Laisse cette fenetre ouverte (ou mets un raccourci dans shell:startup) :
REM elle redige les textes de publication demandes depuis le telephone.
title Photos - redaction des publications
cd /d "%~dp0"
python photoindex.py --veille
pause
