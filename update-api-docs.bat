@echo off
REM ========================================
REM DVideo API Documentation Generator
REM ========================================

echo.
echo ========================================
echo    DVideo API Documentation Generator
echo ========================================
echo.
echo Generating API documentation...
echo.

echo [Step 1/2] Generating HTML, Postman, OpenAPI docs...
call php artisan scribe:generate --force
if errorlevel 1 (
    echo.
    echo ERROR: Failed to generate HTML documentation!
    echo Please check if Laravel is properly configured.
    pause
    exit /b 1
)

echo.
echo [Step 2/2] Generating Markdown documentation...
call php generate-markdown-docs.php
if errorlevel 1 (
    echo.
    echo ERROR: Failed to generate Markdown documentation!
    echo Please check if the script exists.
    pause
    exit /b 1
)

echo.
echo ========================================
echo    SUCCESS! Documentation Generated
echo ========================================
echo.
echo Generated Files:
echo.
echo [1] Markdown Documentation
echo     Location: docs\API.md
echo     Size: ~86 KB
echo     Features:
echo       - Easy to read and search
echo       - Can be opened in any Markdown editor
echo       - Recommended tools: VS Code, Typora
echo       - GitHub/GitLab preview supported
echo.
echo [2] HTML Documentation
echo     URL: http://localhost:8000/docs
echo     Features:
echo       - Beautiful web interface
echo       - Try It Out feature (test APIs in browser)
echo       - 4 language examples (bash, js, php, python)
echo       - Responsive design
echo.
echo [3] Postman Collection
echo     Location: public\docs\collection.json
echo     Usage:
echo       - Open Postman
echo       - File -^> Import
echo       - Select collection.json
echo       - Start testing APIs
echo.
echo [4] OpenAPI Specification
echo     Location: public\docs\openapi.yaml
echo     Standard: OpenAPI 3.0.3
echo     Compatible with: Swagger UI, Insomnia, etc.
echo.
echo ========================================
echo.
echo Quick Start:
echo   1. View Markdown: code docs\API.md
echo   2. View HTML: http://localhost:8000/docs
echo   3. Import to Postman: public\docs\collection.json
echo.
echo ========================================
echo.
pause

