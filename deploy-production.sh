#!/bin/bash
# Production Deployment Script for Sparking Asia TimeTracker
# This script optimizes and prepares the application for production

echo "========================================"
echo "Sparking Asia TimeTracker - Production Build"
echo "========================================"
echo ""

# Step 1: Install/Update Dependencies
echo "[1/8] Installing PHP dependencies..."
composer install --optimize-autoloader --no-dev
if [ $? -ne 0 ]; then
    echo "ERROR: Composer install failed!"
    exit 1
fi

echo "[2/8] Installing Node dependencies..."
npm ci --production=false
if [ $? -ne 0 ]; then
    echo "ERROR: NPM install failed!"
    exit 1
fi

# Step 2: Build Frontend Assets
echo "[3/8] Building optimized frontend assets..."
npm run build
if [ $? -ne 0 ]; then
    echo "ERROR: Build failed!"
    exit 1
fi

# Step 3: Clear all caches
echo "[4/8] Clearing caches..."
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Step 4: Optimize Laravel
echo "[5/8] Optimizing Laravel configuration..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Step 5: Run database migrations (optional - comment out if not needed)
echo "[6/8] Running database migrations..."
# Uncomment the line below if you want to run migrations
# php artisan migrate --force

# Step 6: Storage permissions
echo "[7/8] Setting up storage..."
php artisan storage:link

# Step 7: Optimize Composer autoloader
echo "[8/8] Optimizing Composer autoloader..."
composer dump-autoload --optimize --classmap-authoritative

echo ""
echo "========================================"
echo "Production build completed successfully!"
echo "========================================"
echo ""

echo "IMPORTANT: Before deploying to production server:"
echo "1. Update .env.production with your production database credentials"
echo "2. Update APP_URL in .env.production to your production domain"
echo "3. Configure your mail settings (MAIL_HOST, MAIL_PORT, etc.)"
echo "4. Ensure proper file permissions (storage and bootstrap/cache)"
echo "5. Copy .env.production to .env on your production server"
echo ""
echo "Built files are ready in the public/build directory"
echo ""
