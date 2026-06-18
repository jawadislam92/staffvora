#!/bin/bash

# Production Readiness Checklist Script
# Run this script before deploying to production

echo "🚀 Production Readiness Checker"
echo "================================"
echo ""

ERRORS=0
WARNINGS=0

# Colors for output
RED='\033[0;31m'
YELLOW='\033[1;33m'
GREEN='\033[0;32m'
NC='\033[0m' # No Color

# Check 1: Environment file
echo "📋 Checking environment configuration..."
if [ -f ".env" ]; then
    echo -e "${GREEN}✓${NC} .env file exists"
    
    # Check APP_ENV
    APP_ENV=$(grep "^APP_ENV=" .env | cut -d '=' -f2)
    if [ "$APP_ENV" == "production" ]; then
        echo -e "${GREEN}✓${NC} APP_ENV is set to production"
    else
        echo -e "${YELLOW}⚠${NC} APP_ENV is not set to production (current: $APP_ENV)"
        WARNINGS=$((WARNINGS + 1))
    fi
    
    # Check APP_DEBUG
    APP_DEBUG=$(grep "^APP_DEBUG=" .env | cut -d '=' -f2)
    if [ "$APP_DEBUG" == "false" ]; then
        echo -e "${GREEN}✓${NC} APP_DEBUG is set to false"
    else
        echo -e "${RED}✗${NC} APP_DEBUG should be false in production (current: $APP_DEBUG)"
        ERRORS=$((ERRORS + 1))
    fi
    
    # Check APP_KEY
    APP_KEY=$(grep "^APP_KEY=" .env | cut -d '=' -f2)
    if [ -n "$APP_KEY" ]; then
        echo -e "${GREEN}✓${NC} APP_KEY is set"
    else
        echo -e "${RED}✗${NC} APP_KEY is not set. Run: php artisan key:generate"
        ERRORS=$((ERRORS + 1))
    fi
else
    echo -e "${RED}✗${NC} .env file not found"
    ERRORS=$((ERRORS + 1))
fi
echo ""

# Check 2: Dependencies
echo "📦 Checking dependencies..."
if [ -d "vendor" ]; then
    echo -e "${GREEN}✓${NC} Composer dependencies installed"
else
    echo -e "${RED}✗${NC} Composer dependencies not installed. Run: composer install"
    ERRORS=$((ERRORS + 1))
fi

if [ -d "node_modules" ]; then
    echo -e "${GREEN}✓${NC} NPM dependencies installed"
else
    echo -e "${RED}✗${NC} NPM dependencies not installed. Run: npm install"
    ERRORS=$((ERRORS + 1))
fi
echo ""

# Check 3: Built assets
echo "🎨 Checking built assets..."
if [ -d "public/build" ]; then
    echo -e "${GREEN}✓${NC} Assets have been built"
    
    # Check manifest file
    if [ -f "public/build/manifest.json" ]; then
        echo -e "${GREEN}✓${NC} Manifest file exists"
    else
        echo -e "${RED}✗${NC} Manifest file missing. Run: npm run build"
        ERRORS=$((ERRORS + 1))
    fi
else
    echo -e "${RED}✗${NC} Assets not built. Run: npm run build:production"
    ERRORS=$((ERRORS + 1))
fi
echo ""

# Check 4: Storage permissions
echo "🔒 Checking file permissions..."
if [ -w "storage" ]; then
    echo -e "${GREEN}✓${NC} Storage directory is writable"
else
    echo -e "${RED}✗${NC} Storage directory is not writable"
    ERRORS=$((ERRORS + 1))
fi

if [ -w "bootstrap/cache" ]; then
    echo -e "${GREEN}✓${NC} Bootstrap cache is writable"
else
    echo -e "${RED}✗${NC} Bootstrap cache is not writable"
    ERRORS=$((ERRORS + 1))
fi
echo ""

# Check 5: Database connection
echo "🗄️  Checking database connection..."
php artisan tinker --execute="try { DB::connection()->getPdo(); echo 'Connected'; } catch (Exception \$e) { echo 'Failed'; exit(1); }" > /dev/null 2>&1
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓${NC} Database connection successful"
else
    echo -e "${RED}✗${NC} Database connection failed"
    ERRORS=$((ERRORS + 1))
fi
echo ""

# Check 6: Optimization status
echo "⚡ Checking optimization status..."
if [ -f "bootstrap/cache/config.php" ]; then
    echo -e "${GREEN}✓${NC} Configuration is cached"
else
    echo -e "${YELLOW}⚠${NC} Configuration not cached. Run: php artisan config:cache"
    WARNINGS=$((WARNINGS + 1))
fi

if [ -f "bootstrap/cache/routes-v7.php" ]; then
    echo -e "${GREEN}✓${NC} Routes are cached"
else
    echo -e "${YELLOW}⚠${NC} Routes not cached. Run: php artisan route:cache"
    WARNINGS=$((WARNINGS + 1))
fi

if [ -f "bootstrap/cache/views.php" ] || [ "$(ls -A storage/framework/views)" ]; then
    echo -e "${GREEN}✓${NC} Views are cached"
else
    echo -e "${YELLOW}⚠${NC} Views not cached. Run: php artisan view:cache"
    WARNINGS=$((WARNINGS + 1))
fi
echo ""

# Check 7: Security
echo "🔐 Checking security settings..."
if [ -f "public/.htaccess" ]; then
    echo -e "${GREEN}✓${NC} .htaccess file exists"
else
    echo -e "${YELLOW}⚠${NC} .htaccess file not found (OK if using Nginx)"
    WARNINGS=$((WARNINGS + 1))
fi

# Check if .env is accessible via web (basic check)
if [ -f "public/.env" ]; then
    echo -e "${RED}✗${NC} .env file found in public directory! This is a SECURITY RISK!"
    ERRORS=$((ERRORS + 1))
else
    echo -e "${GREEN}✓${NC} .env file not in public directory"
fi
echo ""

# Final summary
echo "================================"
echo "📊 Summary"
echo "================================"
echo -e "Errors: ${RED}$ERRORS${NC}"
echo -e "Warnings: ${YELLOW}$WARNINGS${NC}"
echo ""

if [ $ERRORS -gt 0 ]; then
    echo -e "${RED}❌ NOT READY FOR PRODUCTION${NC}"
    echo "Please fix the errors above before deploying."
    exit 1
elif [ $WARNINGS -gt 0 ]; then
    echo -e "${YELLOW}⚠️  READY WITH WARNINGS${NC}"
    echo "You can deploy, but consider addressing the warnings for optimal performance."
    exit 0
else
    echo -e "${GREEN}✅ READY FOR PRODUCTION${NC}"
    echo "All checks passed! You're good to deploy."
    exit 0
fi
