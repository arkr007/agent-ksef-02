@echo off
setlocal
cd /d "%~dp0"
python "%~dp0agent-ksef-helper.py"
if errorlevel 1 (
  echo.
  echo Helper zakonczyl sie bledem. Nacisnij dowolny klawisz, aby zamknac okno.
  pause >nul
)
