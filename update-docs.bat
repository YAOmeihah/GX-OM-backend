@echo off
chcp 65001 >nul
echo.
echo ========================================
echo    DVideo API Document Generator
echo ========================================
echo.
echo Generating API documentation...
echo.

echo [1/2] Generating HTML, Postman, OpenAPI docs...
php artisan scribe:generate --force

echo.
echo [2/2] Generating Markdown documentation...
php generate-markdown-docs.php

echo.
echo ========================================
echo    Documentation Generated Successfully!
echo ========================================
echo.
echo Markdown Doc: docs\API.md
echo    - Open with any Markdown editor
echo    - Recommended: VS Code, Typora
echo.
echo HTML Doc: http://localhost:8000/docs
echo    - Beautiful web interface
echo    - Try It Out feature
echo.
echo Postman Collection: public\docs\collection.json
echo    - Import to Postman for testing
echo.
echo OpenAPI Spec: public\docs\openapi.yaml
echo    - OpenAPI 3.0.3 standard
echo.
echo ========================================
echo.
pause

