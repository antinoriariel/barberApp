@echo off
rem iniciar-servidor.bat - Levanta la app en http://localhost:8000 (servidor de desarrollo de PHP).
rem Dejar esta ventana abierta mientras se usa la app; cerrarla apaga el servidor.
cd /d "%~dp0"
echo Navaja ^& Co. en http://localhost:8000  (Ctrl+C o cerrar la ventana para detener)
php -S 0.0.0.0:8000 -t public public/router.php
pause
